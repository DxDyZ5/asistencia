<?php
// config_evaluacion.php - CONFIGURACIÓN DE CRITERIOS Y MÉTODO DE CALIFICACIÓN
require 'auth.php';
verificarPermiso(['admin']); // Solo Admin define las reglas

// --- 1. CONFIGURACIÓN BASE DE DATOS ---
try {
    // Tabla para los criterios generales (deben sumar 100)
    $pdo->exec("CREATE TABLE IF NOT EXISTS criterios_evaluacion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        descripcion VARCHAR(255),
        peso_maximo INT NOT NULL DEFAULT 0,
        es_sistema TINYINT DEFAULT 0, -- 1 si se calcula solo (ej. asistencia), 0 si es manual
        variable_sistema VARCHAR(50) NULL -- ej: 'horas_extras', 'ausencias'
    )");

    // Insertar datos por defecto si está vacía
    $check = $pdo->query("SELECT COUNT(*) FROM criterios_evaluacion")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("INSERT INTO criterios_evaluacion (nombre, descripcion, peso_maximo, es_sistema, variable_sistema) VALUES 
            ('Puntualidad y Asistencia', 'Calculado por el sistema en base a entradas/salidas', 40, 1, 'asistencia'),
            ('Horas Extras / Esfuerzo Extra', 'Puntos adicionales por tiempo extra trabajado', 20, 1, 'horas_extras'),
            ('Desempeño y Calidad', 'Evaluación subjetiva del supervisor', 25, 0, NULL),
            ('Trabajo en Equipo', 'Colaboración con compañeros', 15, 0, NULL)
        ");
    }

    // Tabla para reglas específicas (ej. valor de hora extra)
    $pdo->exec("CREATE TABLE IF NOT EXISTS reglas_calculo (
        clave VARCHAR(50) PRIMARY KEY,
        valor DECIMAL(10,2) NOT NULL,
        descripcion VARCHAR(255)
    )");
    
    // Reglas por defecto
    $pdo->exec("INSERT IGNORE INTO reglas_calculo (clave, valor, descripcion) VALUES 
        ('valor_hora_extra', 2.5, 'Puntos otorgados por cada hora extra trabajada'),
        ('max_puntos_extras', 20, 'Tope máximo de puntos por horas extras')
    ");

} catch (PDOException $e) { die("Error DB Init: " . $e->getMessage()); }

$mensaje = '';
$tipo_msg = '';

// --- 2. PROCESAR GUARDADO DE CRITERIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_criterios'])) {
    try {
        $pdo->beginTransaction();
        
        // 1. Guardar Criterios Generales
        if (isset($_POST['criterios'])) {
            $suma_total = 0;
            foreach ($_POST['criterios'] as $id => $data) {
                $peso = (int)$data['peso'];
                $suma_total += $peso;
                
                $stmt = $pdo->prepare("UPDATE criterios_evaluacion SET nombre = ?, descripcion = ?, peso_maximo = ? WHERE id = ?");
                $stmt->execute([$data['nombre'], $data['desc'], $peso, $id]);
            }
            
            if ($suma_total !== 100) {
                throw new Exception("La suma de los criterios debe ser exactamente 100. Actual: $suma_total");
            }
        }
        
        // 2. Guardar Reglas Específicas
        if (isset($_POST['reglas'])) {
            foreach ($_POST['reglas'] as $clave => $valor) {
                $stmt = $pdo->prepare("UPDATE reglas_calculo SET valor = ? WHERE clave = ?");
                $stmt->execute([$valor, $clave]);
            }
        }

        // 3. Agregar Nuevo Criterio (Si aplica)
        if (!empty($_POST['nuevo_nombre']) && !empty($_POST['nuevo_peso'])) {
            $stmt = $pdo->prepare("INSERT INTO criterios_evaluacion (nombre, descripcion, peso_maximo) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['nuevo_nombre'], $_POST['nuevo_desc'], $_POST['nuevo_peso']]);
            $mensaje = "⚠️ Se agregó un criterio nuevo. Revisa que la suma sea 100.";
        }

        $pdo->commit();
        $mensaje = "✅ Configuración de evaluación guardada correctamente.";
        $tipo_msg = "success";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipo_msg = "error";
    }
}

// --- 3. ELIMINAR CRITERIO ---
if (isset($_GET['borrar_id'])) {
    $pdo->prepare("DELETE FROM criterios_evaluacion WHERE id = ? AND es_sistema = 0")->execute([$_GET['borrar_id']]);
    header("Location: config_evaluacion.php"); exit;
}

// --- 4. CARGAR DATOS ---
$criterios = $pdo->query("SELECT * FROM criterios_evaluacion ORDER BY es_sistema DESC, id ASC")->fetchAll();
$reglas = $pdo->query("SELECT * FROM reglas_calculo")->fetchAll(PDO::FETCH_KEY_PAIR); // Clave => Valor

require 'layout_head.php';
?>

<div class="max-w-5xl mx-auto">
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800"><i class="fas fa-tasks text-blue-600 mr-2"></i>Método de Calificación</h1>
            <p class="text-sm text-gray-500">Define las variables y pesos para la evaluación de desempeño (Total: 100 pts).</p>
        </div>
        <div class="text-right">
            <span id="total-badge" class="text-2xl font-black text-gray-400 bg-white px-4 py-2 rounded-lg shadow border border-gray-200">
                0 / 100
            </span>
        </div>
    </div>

    <?php if($mensaje): ?>
        <div class="mb-6 p-4 rounded-lg border shadow-sm <?php echo $tipo_msg=='success'?'bg-green-100 border-green-400 text-green-800':'bg-red-100 border-red-400 text-red-800'; ?>">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="evalForm">
        <input type="hidden" name="guardar_criterios" value="1">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- COLUMNA IZQ: DISTRIBUCIÓN DE PUNTOS -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- BARRA DE PROGRESO -->
                <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-200 sticky top-4 z-10">
                    <div class="flex justify-between text-xs font-bold text-gray-500 mb-1 uppercase">
                        <span>Distribución de Peso</span>
                        <span id="txt-status">Pendiente</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
                        <div id="progress-bar" class="bg-blue-600 h-4 rounded-full transition-all duration-500" style="width: 0%"></div>
                    </div>
                    <p class="text-[10px] text-gray-400 mt-1">* La suma de todos los criterios debe ser exactamente 100.</p>
                </div>

                <!-- LISTA DE CRITERIOS -->
                <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
                    <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex justify-between">
                        <h3 class="font-bold text-gray-700">Variables de Evaluación</h3>
                    </div>
                    
                    <div class="divide-y divide-gray-100">
                        <?php foreach($criterios as $c): ?>
                        <div class="p-4 flex items-start gap-4 hover:bg-gray-50 transition">
                            <div class="pt-2">
                                <?php if($c['es_sistema']): ?>
                                    <span class="bg-blue-100 text-blue-700 text-[10px] font-bold px-2 py-1 rounded border border-blue-200">AUTO</span>
                                <?php else: ?>
                                    <span class="bg-yellow-100 text-yellow-700 text-[10px] font-bold px-2 py-1 rounded border border-yellow-200">MANUAL</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex-grow">
                                <input type="text" name="criterios[<?php echo $c['id']; ?>][nombre]" value="<?php echo htmlspecialchars($c['nombre']); ?>" class="font-bold text-gray-800 w-full border-none bg-transparent focus:ring-0 p-0 mb-1" placeholder="Nombre del Criterio">
                                <input type="text" name="criterios[<?php echo $c['id']; ?>][desc]" value="<?php echo htmlspecialchars($c['descripcion']); ?>" class="text-xs text-gray-500 w-full border-none bg-transparent focus:ring-0 p-0" placeholder="Descripción breve">
                            </div>
                            
                            <div class="flex flex-col items-end">
                                <div class="flex items-center gap-2">
                                    <input type="number" name="criterios[<?php echo $c['id']; ?>][peso]" value="<?php echo $c['peso_maximo']; ?>" class="input-peso w-16 text-center font-bold border border-gray-300 rounded px-2 py-1 text-sm focus:border-blue-500 outline-none" min="0" max="100">
                                    <span class="text-xs font-bold text-gray-400">pts</span>
                                </div>
                                <?php if(!$c['es_sistema']): ?>
                                    <a href="?borrar_id=<?php echo $c['id']; ?>" class="text-[10px] text-red-400 hover:text-red-600 mt-1" onclick="return confirm('¿Eliminar este criterio?')">Eliminar</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <!-- AGREGAR NUEVO -->
                        <div class="p-4 bg-gray-50 border-t border-dashed border-gray-300">
                            <h4 class="text-xs font-bold text-gray-500 mb-2 uppercase">+ Agregar Variable Personalizada</h4>
                            <div class="flex gap-2">
                                <input type="text" name="nuevo_nombre" placeholder="Ej: Iniciativa" class="flex-grow border rounded px-3 py-1 text-sm">
                                <input type="text" name="nuevo_desc" placeholder="Descripción..." class="flex-grow border rounded px-3 py-1 text-sm">
                                <input type="number" name="nuevo_peso" placeholder="0" class="w-16 border rounded px-2 py-1 text-sm text-center">
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- COLUMNA DER: LÓGICA DE VARIABLES (HORAS EXTRAS) -->
            <div class="space-y-6">
                
                <!-- CONFIGURACIÓN HORAS EXTRAS -->
                <div class="bg-white rounded-xl shadow border-l-4 border-indigo-500 overflow-hidden">
                    <div class="p-4 border-b border-gray-100 bg-indigo-50">
                        <h3 class="font-bold text-indigo-900 text-sm flex items-center gap-2">
                            <i class="fas fa-clock"></i> Lógica: Horas Extras
                        </h3>
                        <p class="text-[10px] text-indigo-600 mt-1">Define cómo el sistema convierte el tiempo extra en puntos.</p>
                    </div>
                    <div class="p-4 space-y-4">
                        
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Valor por Hora Extra</label>
                            <div class="flex items-center gap-2">
                                <input type="number" step="0.1" name="reglas[valor_hora_extra]" value="<?php echo $reglas['valor_hora_extra'] ?? 2.5; ?>" class="border border-indigo-200 rounded px-3 py-2 w-full font-bold text-indigo-700">
                                <span class="text-xs text-gray-500">pts/h</span>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-1">Ej: Si trabaja 2 horas extras, gana <?php echo ($reglas['valor_hora_extra'] ?? 2.5) * 2; ?> pts.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Tope Máximo (Cap)</label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="reglas[max_puntos_extras]" value="<?php echo $reglas['max_puntos_extras'] ?? 20; ?>" class="border border-indigo-200 rounded px-3 py-2 w-full font-bold text-indigo-700">
                                <span class="text-xs text-gray-500">pts</span>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-1">Nadie puede recibir más de estos puntos por horas extras, aunque trabaje más.</p>
                        </div>

                        <div class="bg-yellow-50 p-3 rounded border border-yellow-100">
                            <h4 class="text-[10px] font-bold text-yellow-800 uppercase mb-1">Recomendación del Sistema:</h4>
                            <p class="text-xs text-yellow-700 leading-tight">
                                Se sugiere asignar un valor alto a las horas extras si se busca incentivar la productividad fuera de horario, pero mantener un tope para no desbalancear la evaluación general.
                            </p>
                        </div>

                    </div>
                </div>

                <!-- RESUMEN DE IMPACTO -->
                <div class="bg-white rounded-xl shadow border border-gray-200 p-4">
                    <h3 class="font-bold text-gray-700 text-sm mb-3">Impacto en Evaluación Final</h3>
                    <ul class="text-xs space-y-2 text-gray-600">
                        <li class="flex justify-between">
                            <span>Puntualidad (Auto):</span>
                            <span class="font-bold">Calculado por Reloj</span>
                        </li>
                        <li class="flex justify-between">
                            <span>Horas Extras (Auto):</span>
                            <span class="font-bold">Regla definida arriba</span>
                        </li>
                        <li class="flex justify-between">
                            <span>Variables Manuales:</span>
                            <span class="font-bold">Asignadas por Supervisor</span>
                        </li>
                    </ul>
                </div>

                <button type="submit" id="btn-save" class="w-full bg-slate-800 hover:bg-slate-900 text-white font-bold py-3 rounded-lg shadow-lg transition transform active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-save mr-2"></i> Guardar Configuración
                </button>

            </div>
        </div>
    </form>
</div>

<script>
    // Lógica Frontend para calcular 100 puntos en tiempo real
    const inputs = document.querySelectorAll('.input-peso');
    const progressBar = document.getElementById('progress-bar');
    const badge = document.getElementById('total-badge');
    const txtStatus = document.getElementById('txt-status');
    const btnSave = document.getElementById('btn-save');

    function calcularTotal() {
        let total = 0;
        inputs.forEach(input => {
            let val = parseInt(input.value) || 0;
            total += val;
        });

        // Actualizar UI
        badge.innerText = total + " / 100";
        progressBar.style.width = Math.min(total, 100) + "%";

        if (total === 100) {
            progressBar.classList.remove('bg-red-500', 'bg-yellow-500');
            progressBar.classList.add('bg-green-500');
            badge.classList.remove('text-red-500', 'text-yellow-500');
            badge.classList.add('text-green-600');
            txtStatus.innerText = "¡Perfecto!";
            txtStatus.className = "text-green-600 uppercase font-bold";
            btnSave.disabled = false;
        } else if (total > 100) {
            progressBar.classList.remove('bg-green-500', 'bg-yellow-500');
            progressBar.classList.add('bg-red-500');
            badge.classList.remove('text-green-600', 'text-yellow-500');
            badge.classList.add('text-red-500');
            txtStatus.innerText = "Excede 100 pts";
            txtStatus.className = "text-red-600 uppercase font-bold";
            btnSave.disabled = true;
        } else {
            progressBar.classList.remove('bg-green-500', 'bg-red-500');
            progressBar.classList.add('bg-yellow-500');
            badge.classList.remove('text-green-600', 'text-red-500');
            badge.classList.add('text-yellow-500');
            txtStatus.innerText = "Faltan " + (100 - total) + " pts";
            txtStatus.className = "text-yellow-600 uppercase font-bold";
            btnSave.disabled = true;
        }
    }

    inputs.forEach(input => {
        input.addEventListener('input', calcularTotal);
    });

    // Calcular al inicio
    calcularTotal();
</script>

<?php require 'layout_footer.php'; ?>