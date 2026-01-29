<?php
// ausencias_prolongadas.php - PANEL AVANZADO: FECHAS DINÁMICAS Y FILTROS GRÁFICOS
ob_start();
require 'auth.php';
verificarPermiso(['admin', 'rrhh']);

// --- AUTO-SETUP BD (Asegurar que soporta 'flexible') ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS excepciones_asistencia (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        id_empleado_reloj INT NOT NULL UNIQUE, 
        tipo ENUM('observacion', 'justificado', 'flexible') NOT NULL, 
        notas VARCHAR(255), 
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("ALTER TABLE excepciones_asistencia MODIFY COLUMN tipo ENUM('observacion', 'justificado', 'flexible') NOT NULL");
} catch (Exception $e) {}

// --- PROCESAR CAMBIOS DE CLASIFICACIÓN (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_empleado'])) {
    try {
        $id_emp = $_POST['id_empleado'];
        $tipo = $_POST['tipo'];
        
        if ($tipo === 'ninguno') {
            $pdo->prepare("DELETE FROM excepciones_asistencia WHERE id_empleado_reloj = ?")->execute([$id_emp]);
        } else {
            $pdo->prepare("REPLACE INTO excepciones_asistencia (id_empleado_reloj, tipo) VALUES (?, ?)")->execute([$id_emp, $tipo]);
        }
        
        // Redirección limpia (mantiene los filtros actuales)
        $query_string = $_SERVER['QUERY_STRING'] ?? '';
        header("Location: ausencias_prolongadas.php?" . $query_string);
        exit;
    } catch (Exception $e) { die("Error: " . $e->getMessage()); }
}

// --- CONFIGURACIÓN DE FECHAS (DEFAULTS) ---
// Por defecto: Últimos 15 días
$default_ini = date('Y-m-d', strtotime('-15 days'));
$default_fin = date('Y-m-d');

$f_inicio = $_GET['f_inicio'] ?? $default_ini;
$f_fin    = $_GET['f_fin']    ?? $default_fin;

// --- CALCULAR DÍAS LABORABLES EN EL RANGO ---
$dias_laborables = 0;
$curr = strtotime($f_inicio);
$stop = strtotime($f_fin);

// Array de feriados en el rango para descontarlos también (Opcional, si tienes tabla feriados)
$feriados = [];
try {
    $feriados = $pdo->query("SELECT fecha FROM feriados WHERE fecha BETWEEN '$f_inicio' AND '$f_fin'")->fetchAll(PDO::FETCH_COLUMN);
} catch(Exception $e) {}

while ($curr <= $stop) {
    $dw = date('w', $curr);
    $fecha_str = date('Y-m-d', $curr);
    // Si no es Domingo(0) ni Sábado(6) y no es feriado
    if ($dw != 0 && $dw != 6 && !in_array($fecha_str, $feriados)) {
        $dias_laborables++; 
    }
    $curr = strtotime('+1 day', $curr);
}

// --- FILTROS ---
$f_search = $_GET['q'] ?? '';
$f_depto  = $_GET['depto'] ?? '';
$f_status = $_GET['status'] ?? ''; // Vacío = Todos
$f_clase  = $_GET['clase'] ?? ''; 

$sql_filters = " WHERE 1=1 ";
$params = [];

if ($f_search) {
    $sql_filters .= " AND (e.nombre_completo LIKE ? OR e.cedula LIKE ? OR e.id_reloj LIKE ?)";
    $params[] = "%$f_search%"; $params[] = "%$f_search%"; $params[] = "%$f_search%";
}
if ($f_depto) {
    $sql_filters .= " AND e.departamento = ?";
    $params[] = $f_depto;
}
if ($f_status) {
    $sql_filters .= " AND e.estatus_nomina = ?";
    $params[] = $f_status;
}
if ($f_clase) {
    if ($f_clase === 'ninguno') $sql_filters .= " AND ex.tipo IS NULL";
    else { $sql_filters .= " AND ex.tipo = ?"; $params[] = $f_clase; }
}

// --- CONSULTA PRINCIPAL ---
$sql = "SELECT 
            e.id_reloj, e.nombre_completo, e.cedula, e.departamento, e.cargo, e.estatus_nomina,
            COUNT(DISTINCT a.fecha) as dias_asistidos,
            ex.tipo as clasificacion
        FROM empleados e
        LEFT JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj AND a.fecha BETWEEN ? AND ?
        LEFT JOIN excepciones_asistencia ex ON e.id_reloj = ex.id_empleado_reloj
        $sql_filters
        GROUP BY e.id_reloj
        ORDER BY dias_asistidos ASC, e.nombre_completo ASC";

// Insertamos las fechas al inicio de los parámetros
array_unshift($params, $f_inicio, $f_fin);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$empleados = $stmt->fetchAll();

$deptos = $pdo->query("SELECT DISTINCT departamento FROM empleados WHERE departamento IS NOT NULL AND departamento != '' ORDER BY departamento")->fetchAll(PDO::FETCH_COLUMN);

// Configuración de Colores UI
$colores = [
    'observacion' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'border' => 'border-yellow-200'],
    'justificado' => ['bg' => 'bg-green-100', 'text' => 'text-green-800', 'border' => 'border-green-200'],
    'flexible'    => ['bg' => 'bg-purple-100', 'text' => 'text-purple-800', 'border' => 'border-purple-200'],
    'ninguno'     => ['bg' => 'bg-gray-50', 'text' => 'text-gray-500', 'border' => 'border-gray-200']
];

require 'layout_head.php';
?>

<div class="max-w-7xl mx-auto pb-20">
    
    <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                <span class="bg-red-100 text-red-600 p-2 rounded-lg"><i class="fas fa-calendar-times"></i></span>
                Control de Ausencias
            </h1>
            <p class="text-sm text-gray-500 mt-1">Días laborables en el periodo seleccionado: <b class="text-gray-800 text-lg"><?php echo $dias_laborables; ?></b></p>
        </div>
    </div>

    <form id="filterForm" method="GET" class="bg-white p-5 rounded-xl shadow-lg border border-gray-200 mb-8">
        
        <div class="grid grid-cols-1 md:grid-cols-12 gap-4 mb-4 border-b border-gray-100 pb-4">
            <div class="md:col-span-4 flex gap-2 items-center bg-gray-50 p-2 rounded-lg border border-gray-200">
                <div class="flex-1">
                    <label class="block text-[10px] font-bold text-gray-400 uppercase">Desde</label>
                    <input type="date" name="f_inicio" value="<?php echo $f_inicio; ?>" onchange="this.form.submit()" class="bg-transparent text-sm font-bold text-gray-700 w-full outline-none">
                </div>
                <div class="text-gray-400"><i class="fas fa-arrow-right"></i></div>
                <div class="flex-1">
                    <label class="block text-[10px] font-bold text-gray-400 uppercase">Hasta</label>
                    <input type="date" name="f_fin" value="<?php echo $f_fin; ?>" onchange="this.form.submit()" class="bg-transparent text-sm font-bold text-gray-700 w-full outline-none">
                </div>
            </div>

            <div class="md:col-span-5 relative">
                <i class="fas fa-search absolute left-3 top-3.5 text-gray-400"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($f_search); ?>" onchange="this.form.submit()" placeholder="Buscar por Nombre o ID..." class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-100 outline-none">
            </div>

            <div class="md:col-span-3">
                <select name="depto" onchange="this.form.submit()" class="w-full py-3 px-3 border border-gray-200 rounded-lg text-sm bg-white font-medium text-gray-600 outline-none">
                    <option value="">Todos los Deptos.</option>
                    <?php foreach($deptos as $d): ?>
                        <option value="<?php echo $d; ?>" <?php echo $f_depto==$d?'selected':''; ?>><?php echo $d; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <div>
                <label class="block text-xs font-bold text-gray-400 uppercase mb-2">Estatus Nómina</label>
                <div class="flex gap-2">
                    <label class="cursor-pointer">
                        <input type="radio" name="status" value="" onchange="this.form.submit()" class="hidden peer" <?php echo empty($f_status)?'checked':''; ?>>
                        <div class="px-3 py-1.5 rounded-md border text-xs font-bold transition peer-checked:bg-slate-800 peer-checked:text-white peer-checked:border-slate-800 bg-white text-gray-500 border-gray-200 hover:bg-gray-50">
                            Todos
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="status" value="En Nomina" onchange="this.form.submit()" class="hidden peer" <?php echo $f_status=='En Nomina'?'checked':''; ?>>
                        <div class="px-3 py-1.5 rounded-md border text-xs font-bold transition peer-checked:bg-green-600 peer-checked:text-white peer-checked:border-green-600 bg-white text-gray-500 border-gray-200 hover:bg-green-50">
                            En Nómina
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="status" value="Fuera de Nomina" onchange="this.form.submit()" class="hidden peer" <?php echo $f_status=='Fuera de Nomina'?'checked':''; ?>>
                        <div class="px-3 py-1.5 rounded-md border text-xs font-bold transition peer-checked:bg-red-500 peer-checked:text-white peer-checked:border-red-500 bg-white text-gray-500 border-gray-200 hover:bg-red-50">
                            Fuera de Nómina
                        </div>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-400 uppercase mb-2">Filtrar por Clasificación</label>
                <div class="flex flex-wrap gap-2">
                    <label class="cursor-pointer opacity-80 hover:opacity-100 transition">
                        <input type="radio" name="clase" value="" onchange="this.form.submit()" class="hidden peer" <?php echo empty($f_clase)?'checked':''; ?>>
                        <span class="px-3 py-1.5 rounded-md border border-gray-300 bg-white text-gray-600 text-xs font-bold peer-checked:ring-2 peer-checked:ring-offset-1 peer-checked:ring-gray-400">Todo</span>
                    </label>
                    
                    <label class="cursor-pointer opacity-80 hover:opacity-100 transition">
                        <input type="radio" name="clase" value="ninguno" onchange="this.form.submit()" class="hidden peer" <?php echo $f_clase=='ninguno'?'checked':''; ?>>
                        <span class="px-3 py-1.5 rounded-md border border-gray-200 bg-gray-50 text-gray-500 text-xs font-bold peer-checked:ring-2 peer-checked:ring-offset-1 peer-checked:ring-gray-400">Pendientes</span>
                    </label>

                    <label class="cursor-pointer hover:scale-105 transition">
                        <input type="radio" name="clase" value="observacion" onchange="this.form.submit()" class="hidden peer" <?php echo $f_clase=='observacion'?'checked':''; ?>>
                        <span class="px-3 py-1.5 rounded-md border border-yellow-300 bg-yellow-100 text-yellow-800 text-xs font-bold peer-checked:ring-2 peer-checked:ring-offset-1 peer-checked:ring-yellow-400 shadow-sm">🟡 Observación</span>
                    </label>

                    <label class="cursor-pointer hover:scale-105 transition">
                        <input type="radio" name="clase" value="justificado" onchange="this.form.submit()" class="hidden peer" <?php echo $f_clase=='justificado'?'checked':''; ?>>
                        <span class="px-3 py-1.5 rounded-md border border-green-300 bg-green-100 text-green-800 text-xs font-bold peer-checked:ring-2 peer-checked:ring-offset-1 peer-checked:ring-green-400 shadow-sm">🟢 Justificado</span>
                    </label>

                    <label class="cursor-pointer hover:scale-105 transition">
                        <input type="radio" name="clase" value="flexible" onchange="this.form.submit()" class="hidden peer" <?php echo $f_clase=='flexible'?'checked':''; ?>>
                        <span class="px-3 py-1.5 rounded-md border border-purple-300 bg-purple-100 text-purple-800 text-xs font-bold peer-checked:ring-2 peer-checked:ring-offset-1 peer-checked:ring-purple-400 shadow-sm">🟣 Flexible</span>
                    </label>
                </div>
            </div>
        </div>
    </form>

    <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 text-gray-500 font-bold uppercase text-[11px] tracking-wider border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-4">Empleado</th>
                        <th class="px-6 py-4">Departamento</th>
                        <th class="px-6 py-4 text-center">Asistencia <br><span class="text-[9px] lowercase opacity-70">(días)</span></th>
                        <th class="px-6 py-4 text-center">Ausencias <br><span class="text-[9px] lowercase opacity-70">(calculadas)</span></th>
                        <th class="px-6 py-4 text-center min-w-[180px]">Clasificación</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php 
                    $contador = 0;
                    foreach ($empleados as $e): 
                        // Cálculo
                        $ausencias = $dias_laborables - $e['dias_asistidos'];
                        if ($ausencias < 0) $ausencias = 0;

                        // Visibilidad inteligente: Si no hay filtro de clase, ocultar "perfectos" sin clasificar
                        if (empty($f_clase) && $ausencias == 0 && empty($e['clasificacion'])) continue;

                        $contador++;
                        $ced_limpia = preg_replace('/[^0-9]/', '', $e['cedula']);
                        $foto = file_exists("fotos/$ced_limpia.jpg") ? "fotos/$ced_limpia.jpg" : "https://ui-avatars.com/api/?name=".urlencode($e['nombre_completo']);

                        $clase_actual = $e['clasificacion'] ?? 'ninguno';
                        $estilo = $colores[$clase_actual];
                    ?>
                    <tr class="hover:bg-slate-50 transition group">
                        <td class="px-6 py-3 flex items-center gap-3">
                            <img src="<?php echo $foto; ?>" class="w-10 h-10 rounded-full object-cover border border-gray-200 shadow-sm">
                            <div>
                                <a href="editar_empleado.php?id=<?php echo $e['id_reloj']; ?>" class="font-bold text-slate-700 hover:text-blue-600 block">
                                    <?php echo htmlspecialchars($e['nombre_completo']); ?>
                                </a>
                                <span class="text-[10px] text-gray-400 font-mono bg-gray-100 px-1 rounded">ID: <?php echo $e['id_reloj']; ?></span>
                                <?php if($e['estatus_nomina'] == 'Fuera de Nomina'): ?>
                                    <span class="text-[9px] text-red-500 font-bold uppercase ml-1">Inactivo</span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td class="px-6 py-3">
                            <div class="font-bold text-gray-600 text-xs"><?php echo htmlspecialchars($e['departamento']); ?></div>
                            <div class="text-[10px] text-gray-400 uppercase tracking-tight"><?php echo htmlspecialchars($e['cargo']); ?></div>
                        </td>

                        <td class="px-6 py-3 text-center">
                            <span class="font-mono text-gray-600 font-bold"><?php echo $e['dias_asistidos']; ?></span>
                            <span class="text-gray-300 text-xs">/ <?php echo $dias_laborables; ?></span>
                        </td>

                        <td class="px-6 py-3 text-center">
                            <?php if ($ausencias >= 3): ?>
                                <div class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-red-100 text-red-600 font-bold shadow-sm animate-pulse border border-red-200">
                                    <?php echo $ausencias; ?>
                                </div>
                            <?php elseif ($ausencias > 0): ?>
                                <div class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-orange-100 text-orange-600 font-bold border border-orange-200">
                                    <?php echo $ausencias; ?>
                                </div>
                            <?php else: ?>
                                <div class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-green-50 text-green-400 font-bold text-xs">
                                    <i class="fas fa-check"></i>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td class="px-6 py-3 text-center">
                            <form method="POST">
                                <input type="hidden" name="id_empleado" value="<?php echo $e['id_reloj']; ?>">
                                <select name="tipo" onchange="this.form.submit()" 
                                    class="w-full text-xs font-bold border rounded-lg shadow-sm focus:ring-2 focus:ring-offset-1 cursor-pointer outline-none px-2 py-1.5 appearance-none text-center transition-all <?php echo $estilo['bg'] . ' ' . $estilo['text'] . ' ' . $estilo['border']; ?>">
                                    <option value="ninguno" <?php echo $clase_actual=='ninguno'?'selected':''; ?>>-- Pendiente --</option>
                                    <option value="observacion" <?php echo $clase_actual=='observacion'?'selected':''; ?>>🟡 Observación</option>
                                    <option value="justificado" <?php echo $clase_actual=='justificado'?'selected':''; ?>>🟢 Justificado</option>
                                    <option value="flexible" <?php echo $clase_actual=='flexible'?'selected':''; ?>>🟣 Flexible</option>
                                </select>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if($contador == 0): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-16 text-center">
                            <div class="inline-block p-4 rounded-full bg-gray-50 mb-3"><i class="fas fa-check-double text-4xl text-gray-300"></i></div>
                            <h3 class="font-bold text-gray-600">Sin novedades</h3>
                            <p class="text-sm text-gray-400">No hay empleados con ausencias que cumplan estos filtros.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php 
$content = ob_get_clean(); 
require 'layout_footer.php'; 
echo $content; 
?>