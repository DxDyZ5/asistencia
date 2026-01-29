<?php
// fusionar_usuarios.php - HERRAMIENTA: FUSIÓN INTELIGENTE Y ASISTIDA
require 'auth.php';
verificarPermiso(['admin']);

// --- AJAX: BUSCADOR DE USUARIOS ---
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $term = trim($_GET['q']);
    $exclude = $_GET['exclude'] ?? 0;
    
    if (strlen($term) < 2) { echo json_encode([]); exit; }

    try {
        $sql = "SELECT id_reloj, nombre_completo, cedula, departamento, cargo 
                FROM empleados 
                WHERE (nombre_completo LIKE ? OR cedula LIKE ? OR id_reloj LIKE ?) 
                AND id_reloj != ? 
                LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$term%", "%$term%", "$term%", $exclude]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Añadir ruta de foto simulada
        foreach ($results as &$r) {
            $ced_clean = preg_replace('/[^0-9]/', '', $r['cedula']);
            $r['foto'] = file_exists("fotos/$ced_clean.jpg") ? "fotos/$ced_clean.jpg" : "https://ui-avatars.com/api/?name=".urlencode($r['nombre_completo']);
        }
        echo json_encode($results);
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

$mensaje = '';
$tipo_msg = '';

// =============================================================================
// ACCIÓN: EJECUTAR FUSIÓN (MANUAL O AUTOMÁTICA)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CASO 1: FUSIÓN MANUAL ASISTIDA
    if (isset($_POST['accion']) && $_POST['accion'] === 'fusionar_manual') {
        $id_origen  = $_POST['manual_id_origen'];
        $id_destino = $_POST['manual_id_destino'];

        if (empty($id_origen) || empty($id_destino) || $id_origen == $id_destino) {
            $mensaje = "⚠ Selección inválida. Debes elegir dos usuarios distintos."; $tipo_msg = "error";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Mover datos
                $pdo->prepare("UPDATE IGNORE asistencia SET id_empleado_reloj = ? WHERE id_empleado_reloj = ?")->execute([$id_destino, $id_origen]);
                $pdo->prepare("UPDATE IGNORE documentos_empleado SET id_empleado_reloj = ? WHERE id_empleado_reloj = ?")->execute([$id_destino, $id_origen]);
                $pdo->prepare("UPDATE IGNORE excepciones_asistencia SET id_empleado_reloj = ? WHERE id_empleado_reloj = ?")->execute([$id_destino, $id_origen]);

                // Llenar datos faltantes en destino desde origen
                $u_origen = $pdo->query("SELECT * FROM empleados WHERE id_reloj = $id_origen")->fetch();
                $u_destino = $pdo->query("SELECT * FROM empleados WHERE id_reloj = $id_destino")->fetch();
                
                $updates = []; $params = [];
                if (empty($u_destino['cedula']) && !empty($u_origen['cedula'])) { $updates[] = "cedula = ?"; $params[] = $u_origen['cedula']; }
                if (empty($u_destino['telefono']) && !empty($u_origen['telefono'])) { $updates[] = "telefono = ?"; $params[] = $u_origen['telefono']; }
                
                if (!empty($updates)) {
                    $params[] = $id_destino;
                    $pdo->prepare("UPDATE empleados SET " . implode(", ", $updates) . " WHERE id_reloj = ?")->execute($params);
                }

                // Eliminar origen
                $pdo->prepare("DELETE FROM asistencia WHERE id_empleado_reloj = ?")->execute([$id_origen]);
                $pdo->prepare("DELETE FROM empleados WHERE id_reloj = ?")->execute([$id_origen]);

                $pdo->commit();
                $mensaje = "✅ Fusión completada: <b>{$u_origen['nombre_completo']}</b> ha sido unificado con <b>{$u_destino['nombre_completo']}</b>."; $tipo_msg = "success";
            } catch (Exception $e) {
                $pdo->rollBack(); $mensaje = "Error: " . $e->getMessage(); $tipo_msg = "error";
            }
        }
    }

    // CASO 2: FUSIÓN MASIVA AUTOMÁTICA (CÉDULA EN NOMBRE)
    if (isset($_POST['accion']) && $_POST['accion'] === 'fusionar_todo') {
        // ... (Misma lógica anterior para mantener funcionalidad) ...
        try {
            $pdo->beginTransaction();
            $sql = "SELECT * FROM empleados WHERE nombre_completo REGEXP '[0-9]{9,}'";
            $candidatos = $pdo->query($sql)->fetchAll();
            $count = 0;
            foreach ($candidatos as $sucio) {
                if (preg_match('/^(.*?)\s+(\d{9,13})$/', trim($sucio['nombre_completo']), $matches)) {
                    $nombre_limpio = trim($matches[1]); $cedula_rec = $matches[2]; $id_origen = $sucio['id_reloj'];
                    $stmtBusca = $pdo->prepare("SELECT id_reloj FROM empleados WHERE nombre_completo = ? AND id_reloj != ? LIMIT 1");
                    $stmtBusca->execute([$nombre_limpio, $id_origen]);
                    $id_destino = $stmtBusca->fetchColumn();
                    if ($id_destino) {
                        $pdo->prepare("UPDATE IGNORE asistencia SET id_empleado_reloj = ? WHERE id_empleado_reloj = ?")->execute([$id_destino, $id_origen]);
                        $pdo->prepare("UPDATE IGNORE documentos_empleado SET id_empleado_reloj = ? WHERE id_empleado_reloj = ?")->execute([$id_destino, $id_origen]);
                        $pdo->prepare("UPDATE IGNORE excepciones_asistencia SET id_empleado_reloj = ? WHERE id_empleado_reloj = ?")->execute([$id_destino, $id_origen]);
                        $stmtCheck = $pdo->prepare("SELECT cedula FROM empleados WHERE id_reloj = ?"); $stmtCheck->execute([$id_destino]);
                        if (empty($stmtCheck->fetchColumn())) { $pdo->prepare("UPDATE empleados SET cedula = ? WHERE id_reloj = ?")->execute([$cedula_rec, $id_destino]); }
                        $pdo->prepare("DELETE FROM asistencia WHERE id_empleado_reloj = ?")->execute([$id_origen]); 
                        $pdo->prepare("DELETE FROM empleados WHERE id_reloj = ?")->execute([$id_origen]);
                        $count++;
                    }
                }
            }
            $pdo->commit(); $mensaje = "⚡ Fusión Masiva: <b>$count</b> usuarios unificados."; $tipo_msg = "success";
        } catch (Exception $e) { $pdo->rollBack(); $mensaje = "Error: " . $e->getMessage(); $tipo_msg = "error"; }
    }

    // CASO 3: LIMPIEZA MASIVA
    if (isset($_POST['accion']) && $_POST['accion'] === 'limpiar_todo') {
        try {
            $pdo->beginTransaction();
            $sql = "SELECT * FROM empleados WHERE nombre_completo REGEXP '[0-9]{9,}'";
            $candidatos = $pdo->query($sql)->fetchAll();
            $count = 0;
            foreach ($candidatos as $sucio) {
                if (preg_match('/^(.*?)\s+(\d{9,13})$/', trim($sucio['nombre_completo']), $matches)) {
                    $nombre_limpio = trim($matches[1]); $cedula_rec = $matches[2]; $id_target = $sucio['id_reloj'];
                    $stmtCheck = $pdo->prepare("SELECT id_reloj FROM empleados WHERE nombre_completo = ? AND id_reloj != ?");
                    $stmtCheck->execute([$nombre_limpio, $id_target]);
                    if (!$stmtCheck->fetch()) {
                        $pdo->prepare("UPDATE empleados SET nombre_completo = ?, cedula = ? WHERE id_reloj = ?")->execute([$nombre_limpio, $cedula_rec, $id_target]);
                        $count++;
                    }
                }
            }
            $pdo->commit(); $mensaje = "✨ Limpieza Masiva: <b>$count</b> nombres corregidos."; $tipo_msg = "success";
        } catch (Exception $e) { $pdo->rollBack(); $mensaje = "Error: " . $e->getMessage(); $tipo_msg = "error"; }
    }
}

// --- ANÁLISIS AUTOMÁTICO (Para el reporte inferior) ---
$candidatos = $pdo->query("SELECT * FROM empleados WHERE nombre_completo REGEXP '[0-9]{9,}' ORDER BY nombre_completo ASC")->fetchAll();
$casos_fusion = []; $cnt_fusionables = 0; $cnt_limpiables = 0;
foreach ($candidatos as $sucio) {
    if (preg_match('/^(.*?)\s+(\d{9,13})$/', trim($sucio['nombre_completo']), $matches)) {
        $nombre_limpio = trim($matches[1]); $posible_cedula = $matches[2];
        $stmtBusca = $pdo->prepare("SELECT * FROM empleados WHERE nombre_completo = ? AND id_reloj != ? LIMIT 1");
        $stmtBusca->execute([$nombre_limpio, $sucio['id_reloj']]);
        $limpio = $stmtBusca->fetch();
        if ($limpio) $cnt_fusionables++; else $cnt_limpiables++;
        $casos_fusion[] = ['sucio' => $sucio, 'nombre_limpio' => $nombre_limpio, 'cedula' => $posible_cedula, 'limpio' => $limpio];
    }
}

require 'layout_head.php';
?>

<div class="max-w-6xl mx-auto pb-20">
    <h1 class="text-2xl font-bold text-gray-800 mb-6 flex items-center"><i class="fas fa-tools text-slate-600 mr-2"></i>Mantenimiento de Usuarios</h1>

    <?php if($mensaje): ?>
        <div class="mb-6 p-4 rounded-lg border shadow-sm <?php echo $tipo_msg=='success'?'bg-green-100 border-green-400 text-green-800':'bg-red-100 border-red-400 text-red-800'; ?>">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-lg border border-indigo-200 mb-8 overflow-hidden">
        <div class="bg-gradient-to-r from-slate-800 to-slate-700 px-6 py-4 border-b border-indigo-200">
            <h2 class="text-white font-bold text-lg"><i class="fas fa-people-arrows mr-2"></i> Fusión Manual Asistida</h2>
            <p class="text-indigo-100 text-xs">Busca y selecciona dos perfiles para unificarlos. El sistema te recomendará coincidencias.</p>
        </div>
        
        <div class="p-6">
            <form method="POST" id="formFusionManual" onsubmit="return validarFusion()">
                <input type="hidden" name="accion" value="fusionar_manual">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 relative">
                    <div class="absolute left-1/2 top-1/2 transform -translate-x-1/2 -translate-y-1/2 z-10 hidden md:block text-slate-300">
                        <i class="fas fa-chevron-right text-4xl"></i>
                    </div>

                    <div class="bg-red-50 p-4 rounded-lg border border-red-200 relative">
                        <span class="absolute -top-3 left-4 bg-red-600 text-white text-xs font-bold px-2 py-1 rounded">ORIGEN (Se elimina)</span>
                        
                        <div class="mt-2 relative">
                            <label class="text-xs font-bold text-gray-500 uppercase">Buscar Usuario Incorrecto</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                                <input type="text" id="search_origen" class="w-full pl-9 pr-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-red-300 outline-none" placeholder="Nombre, Cédula o ID..." autocomplete="off">
                                <div id="list_origen" class="absolute z-50 w-full bg-white shadow-xl rounded-lg mt-1 max-h-60 overflow-y-auto hidden border border-gray-200"></div>
                            </div>
                        </div>

                        <input type="hidden" name="manual_id_origen" id="input_id_origen">
                        <div id="card_origen" class="mt-4 hidden text-center animate-fade-in">
                            <img id="img_origen" src="" class="w-16 h-16 rounded-full mx-auto border-2 border-red-300 object-cover">
                            <h3 id="name_origen" class="font-bold text-gray-800 text-sm mt-2"></h3>
                            <p class="text-xs text-gray-500 font-mono">ID: <span id="txt_id_origen"></span></p>
                            <p id="ced_origen" class="text-xs text-red-600 font-bold mt-1"></p>
                            <button type="button" onclick="resetSelection('origen')" class="mt-2 text-xs text-red-500 hover:underline">Cambiar</button>
                        </div>
                    </div>

                    <div class="bg-green-50 p-4 rounded-lg border border-green-200 relative">
                        <span class="absolute -top-3 left-4 bg-green-600 text-white text-xs font-bold px-2 py-1 rounded">DESTINO (Se conserva)</span>
                        
                        <div class="mt-2 relative">
                            <label class="text-xs font-bold text-gray-500 uppercase">Buscar Usuario Correcto</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                                <input type="text" id="search_destino" class="w-full pl-9 pr-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-green-300 outline-none" placeholder="Nombre, Cédula o ID..." autocomplete="off">
                                <div id="list_destino" class="absolute z-50 w-full bg-white shadow-xl rounded-lg mt-1 max-h-60 overflow-y-auto hidden border border-gray-200"></div>
                            </div>
                        </div>

                        <input type="hidden" name="manual_id_destino" id="input_id_destino">
                        <div id="card_destino" class="mt-4 hidden text-center animate-fade-in">
                            <img id="img_destino" src="" class="w-16 h-16 rounded-full mx-auto border-2 border-green-300 object-cover">
                            <h3 id="name_destino" class="font-bold text-gray-800 text-sm mt-2"></h3>
                            <p class="text-xs text-gray-500 font-mono">ID: <span id="txt_id_destino"></span></p>
                            <p id="ced_destino" class="text-xs text-green-600 font-bold mt-1"></p>
                            <button type="button" onclick="resetSelection('destino')" class="mt-2 text-xs text-green-500 hover:underline">Cambiar</button>
                        </div>
                    </div>
                </div>

                <div class="mt-6 text-center">
                    <button type="submit" id="btn_fusionar" disabled class="bg-gray-400 text-white font-bold py-3 px-8 rounded-lg shadow cursor-not-allowed transition-all">
                        <i class="fas fa-link mr-2"></i> Confirmar Fusión
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4 bg-white p-4 rounded-xl shadow border border-gray-200">
        <div>
            <h3 class="text-md font-bold text-gray-700">Casos Automáticos Detectados</h3>
            <p class="text-xs text-gray-500 mt-1">Nombres con números pegados: <b><?php echo count($casos_fusion); ?></b></p>
        </div>
        <div class="flex gap-2">
            <?php if ($cnt_fusionables > 0): ?>
            <form method="POST"><input type="hidden" name="accion" value="fusionar_todo"><button type="submit" onclick="return confirm('¿Confirmar?')" class="bg-green-600 hover:bg-green-700 text-white text-xs font-bold py-2 px-4 rounded shadow flex items-center gap-2"><i class="fas fa-people-arrows"></i> AUTO-FUSIONAR (<?php echo $cnt_fusionables; ?>)</button></form>
            <?php endif; ?>
            <?php if ($cnt_limpiables > 0): ?>
            <form method="POST"><input type="hidden" name="accion" value="limpiar_todo"><button type="submit" onclick="return confirm('¿Confirmar?')" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-2 px-4 rounded shadow flex items-center gap-2"><i class="fas fa-magic"></i> AUTO-LIMPIAR (<?php echo $cnt_limpiables; ?>)</button></form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($casos_fusion)): ?>
        <div class="grid grid-cols-1 gap-4">
            <?php foreach ($casos_fusion as $caso): $sucio = $caso['sucio']; $limpio = $caso['limpio']; ?>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 flex justify-between items-center">
                <div class="text-sm">
                    <span class="font-bold text-red-600"><?php echo $sucio['nombre_completo']; ?></span>
                    <i class="fas fa-arrow-right mx-2 text-gray-400"></i>
                    <span class="font-bold text-green-600"><?php echo $limpio ? $limpio['nombre_completo'] : $caso['nombre_limpio']; ?></span>
                </div>
                <span class="text-xs bg-gray-100 px-2 py-1 rounded"><?php echo $limpio ? 'Fusionable' : 'Solo Limpieza'; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<script>
// LÓGICA DE BÚSQUEDA Y SELECCIÓN
let typingTimer;
const doneTypingInterval = 300;

function setupSearch(type) {
    const input = document.getElementById('search_' + type);
    const list = document.getElementById('list_' + type);

    input.addEventListener('keyup', () => {
        clearTimeout(typingTimer);
        typingTimer = setTimeout(() => {
            const query = input.value;
            const exclude = type === 'destino' ? document.getElementById('input_id_origen').value : 0;
            
            if (query.length < 2) { list.classList.add('hidden'); return; }

            fetch(`fusionar_usuarios.php?ajax_search=1&q=${encodeURIComponent(query)}&exclude=${exclude}`)
                .then(res => res.json())
                .then(data => {
                    list.innerHTML = '';
                    if (data.length === 0) {
                        list.innerHTML = '<div class="p-3 text-xs text-gray-500">No encontrado</div>';
                    } else {
                        data.forEach(user => {
                            const item = document.createElement('div');
                            item.className = 'p-2 hover:bg-gray-100 cursor-pointer flex items-center gap-2 border-b border-gray-50';
                            item.innerHTML = `<img src="${user.foto}" class="w-8 h-8 rounded-full object-cover">
                                              <div>
                                                  <div class="text-xs font-bold text-gray-700">${user.nombre_completo}</div>
                                                  <div class="text-[10px] text-gray-500">ID: ${user.id_reloj} | ${user.cedula || 'S/C'}</div>
                                              </div>`;
                            item.onclick = () => selectUser(type, user);
                            list.appendChild(item);
                        });
                    }
                    list.classList.remove('hidden');
                });
        }, doneTypingInterval);
    });
}

function selectUser(type, user) {
    document.getElementById('input_id_' + type).value = user.id_reloj;
    document.getElementById('search_' + type).value = '';
    document.getElementById('list_' + type).classList.add('hidden');
    
    // Mostrar Tarjeta
    document.getElementById('card_' + type).classList.remove('hidden');
    document.getElementById('img_' + type).src = user.foto;
    document.getElementById('name_' + type).innerText = user.nombre_completo;
    document.getElementById('txt_id_' + type).innerText = user.id_reloj;
    document.getElementById('ced_' + type).innerText = user.cedula ? user.cedula : 'Sin Cédula';
    
    // Ocultar Input de búsqueda
    document.getElementById('search_' + type).parentElement.parentElement.classList.add('hidden');

    // RECOMENDACIÓN AUTOMÁTICA
    if (type === 'origen') {
        // Limpiar nombre de números para buscar sugerencia
        let nombreLimpio = user.nombre_completo.replace(/[0-9]/g, '').trim();
        if(nombreLimpio.length > 3) {
            const inputDest = document.getElementById('search_destino');
            inputDest.value = nombreLimpio;
            inputDest.dispatchEvent(new Event('keyup')); // Disparar búsqueda automática
            inputDest.focus();
        }
    }

    validateForm();
}

function resetSelection(type) {
    document.getElementById('input_id_' + type).value = '';
    document.getElementById('card_' + type).classList.add('hidden');
    document.getElementById('search_' + type).parentElement.parentElement.classList.remove('hidden');
    validateForm();
}

function validateForm() {
    const ori = document.getElementById('input_id_origen').value;
    const des = document.getElementById('input_id_destino').value;
    const btn = document.getElementById('btn_fusionar');
    
    if (ori && des && ori != des) {
        btn.disabled = false;
        btn.classList.remove('bg-gray-400', 'cursor-not-allowed');
        btn.classList.add('bg-indigo-600', 'hover:bg-indigo-700', 'transform', 'hover:scale-105');
    } else {
        btn.disabled = true;
        btn.classList.add('bg-gray-400', 'cursor-not-allowed');
        btn.classList.remove('bg-indigo-600', 'hover:bg-indigo-700', 'transform', 'hover:scale-105');
    }
}

function validarFusion() {
    return confirm("⚠️ IMPORTANTE:\n\nEl usuario de ORIGEN será ELIMINADO.\nSus asistencias y documentos pasarán al usuario DESTINO.\n\n¿Deseas continuar?");
}

setupSearch('origen');
setupSearch('destino');
</script>

<?php require 'layout_footer.php'; ?>