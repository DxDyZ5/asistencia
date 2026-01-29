<?php
// editar_empleado.php - VERSIÓN: BUSCADOR INTELIGENTE (ID, NOMBRE, CÉDULA)
require 'auth.php'; 

// Conexión
$host = 'localhost';
$db   = 'reynoteja_control_asistencia';
$user = 'reynoteja_carlos';
$pass = 'M22300435397'; 
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) { die("Error DB: " . $e->getMessage()); }

// --- LÓGICA DE BÚSQUEDA INTELIGENTE (Interceptar antes de cargar) ---
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $term = trim($_GET['q']);
    // Limpiar si parece cédula
    $term_cedula = preg_replace('/[^0-9]/', '', $term); 
    
    // Buscar en ID, Cédula, Nombre, Teléfono
    $sqlSearch = "SELECT id_reloj FROM empleados WHERE id_reloj = ? OR nombre_completo LIKE ? OR cedula LIKE ? OR telefono LIKE ?";
    $params = [$term, "%$term%", "%$term%", "%$term%"];
    
    // Si metió números largos, intentamos buscar exacto por cédula limpia también
    if (is_numeric($term_cedula) && strlen($term_cedula) > 5) {
        $sqlSearch .= " OR cedula LIKE ?";
        $params[] = "%$term_cedula%";
    }

    $stmtSearch = $pdo->prepare($sqlSearch);
    $stmtSearch->execute($params);
    $resultados = $stmtSearch->fetchAll(PDO::FETCH_COLUMN);

    if (count($resultados) == 1) {
        // UN SOLO RESULTADO: Ir directo al perfil
        header("Location: editar_empleado.php?id=" . $resultados[0]);
        exit;
    } elseif (count($resultados) > 1) {
        // MUCHOS RESULTADOS: Ir a la lista filtrada
        header("Location: perfil_empleado.php?q=" . urlencode($term));
        exit;
    } else {
        // NINGUNO: Mostrar error en esta misma página si hay ID, si no ir a lista
        $mensaje_error_busqueda = "❌ No se encontró: " . htmlspecialchars($term);
    }
}

// --- AUTO-SETUP COLUMNAS ---
$cols_needed = [
    "ADD COLUMN IF NOT EXISTS telefono VARCHAR(20) AFTER cedula",
    "ADD COLUMN IF NOT EXISTS estatus_nomina VARCHAR(20) DEFAULT 'En Nomina' AFTER cargo",
    "ADD COLUMN IF NOT EXISTS horario_entrada TIME DEFAULT '09:00:00'",
    "ADD COLUMN IF NOT EXISTS horario_salida TIME DEFAULT '18:00:00'",
    "ADD COLUMN IF NOT EXISTS horario_verificado TINYINT(1) DEFAULT 0",
    "ADD COLUMN IF NOT EXISTS vacaciones_inicio DATE DEFAULT NULL",
    "ADD COLUMN IF NOT EXISTS vacaciones_fin DATE DEFAULT NULL",
    "ADD COLUMN IF NOT EXISTS licencia_inicio DATE DEFAULT NULL",
    "ADD COLUMN IF NOT EXISTS licencia_fin DATE DEFAULT NULL",
    "ADD COLUMN IF NOT EXISTS puntuacion INT DEFAULT 100" 
];
foreach ($cols_needed as $sql) { try { $pdo->exec("ALTER TABLE empleados $sql"); } catch (Exception $e) {} }

// --- AUTO-SETUP DOCUMENTOS ---
$pdo->exec("CREATE TABLE IF NOT EXISTS documentos_empleado (id INT AUTO_INCREMENT PRIMARY KEY, id_empleado_reloj INT NOT NULL, titulo VARCHAR(100), nombre_archivo VARCHAR(255), tipo_archivo VARCHAR(50), fecha_subida TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

$mensaje = $mensaje_error_busqueda ?? '';
$tipo_msg = !empty($mensaje_error_busqueda) ? 'error' : '';
$id_reloj = $_GET['id'] ?? null;

// Redirección si no hay ID y no estamos buscando
if (!$id_reloj) { header("Location: perfil_empleado.php"); exit; }

// --- CREAR CARPETAS ---
if (!is_dir('fotos_cedula')) mkdir('fotos_cedula', 0777, true);
if (!is_dir('uploads/docs')) mkdir('uploads/docs', 0777, true);
if (!is_dir('carnets_generados')) mkdir('carnets_generados', 0777, true);

// --- UNIFICACIÓN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unificar_perfil'])) {
    $id_duplicado = $_POST['id_duplicado'];
    $id_destino   = $id_reloj; 
    if ($id_duplicado == $id_destino) {
        $mensaje = "⚠ No puedes unificar el perfil consigo mismo."; $tipo_msg = "error";
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE IGNORE asistencia SET id_empleado_reloj = ? WHERE id_empleado_reloj = ?")->execute([$id_destino, $id_duplicado]);
            $pdo->prepare("DELETE FROM asistencia WHERE id_empleado_reloj = ?")->execute([$id_duplicado]);
            $pdo->prepare("DELETE FROM empleados WHERE id_reloj = ?")->execute([$id_duplicado]);
            $pdo->commit();
            $mensaje = "✅ Perfiles unificados exitosamente."; $tipo_msg = "success";
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje = "❌ Error al unificar: " . $e->getMessage(); $tipo_msg = "error";
        }
    }
}

// --- GUARDAR DATOS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_datos'])) {
    $nombre  = strtoupper(trim($_POST['nombre']));
    $cedula  = preg_replace('/[^0-9]/', '', $_POST['cedula']);
    $telefono = trim($_POST['telefono']);
    $depto   = strtoupper(trim($_POST['departamento']));
    $cargo   = strtoupper(trim($_POST['cargo']));
    $estatus = $_POST['estatus_nomina']; 
    $ingreso = !empty($_POST['fecha_ingreso']) ? $_POST['fecha_ingreso'] : null;
    $tarjeta = trim($_POST['tarjeta']);
    $tipo    = $_POST['tipo_personal'];
    $notas   = trim($_POST['notas_nomina']);
    
    $h_entrada = $_POST['horario_entrada'];
    $h_salida  = $_POST['horario_salida'];
    $h_verif   = isset($_POST['horario_verificado']) ? 1 : 0;
    
    $v_inicio  = !empty($_POST['vacaciones_inicio']) ? $_POST['vacaciones_inicio'] : null;
    $v_fin     = !empty($_POST['vacaciones_fin']) ? $_POST['vacaciones_fin'] : null;
    $l_inicio  = !empty($_POST['licencia_inicio']) ? $_POST['licencia_inicio'] : null;
    $l_fin     = !empty($_POST['licencia_fin']) ? $_POST['licencia_fin'] : null;

    try {
        $sql = "UPDATE empleados SET 
                nombre_completo=?, cedula=?, telefono=?, departamento=?, cargo=?, estatus_nomina=?, fecha_ingreso=?, 
                tarjeta=?, tipo_personal=?, notas_nomina=?,
                horario_entrada=?, horario_salida=?, horario_verificado=?,
                vacaciones_inicio=?, vacaciones_fin=?,
                licencia_inicio=?, licencia_fin=?
                WHERE id_reloj=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $nombre, $cedula, $telefono, $depto, $cargo, $estatus, $ingreso, 
            $tarjeta, $tipo, $notas,
            $h_entrada, $h_salida, $h_verif,
            $v_inicio, $v_fin,
            $l_inicio, $l_fin,
            $id_reloj
        ]);
        $mensaje = "✅ Datos actualizados correctamente."; $tipo_msg = "success";
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage(); $tipo_msg = "error";
    }
}

// --- FOTOS PC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cedula_actual = preg_replace('/[^0-9]/', '', $_POST['cedula_hidden'] ?? '');
    if (isset($_FILES['nueva_foto']) && $_FILES['nueva_foto']['error'] === 0) {
        if ($cedula_actual) move_uploaded_file($_FILES['nueva_foto']['tmp_name'], "fotos/" . $cedula_actual . ".jpg");
    }
}

// --- DOCUMENTOS PC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['doc_archivo']) && $_FILES['doc_archivo']['error'] === 0) {
        $titulo = $_POST['doc_titulo'];
        $ext = pathinfo($_FILES['doc_archivo']['name'], PATHINFO_EXTENSION);
        $nombre_archivo = uniqid() . "_" . $id_reloj . "." . $ext;
        $destino = "uploads/docs/" . $nombre_archivo;
        if (move_uploaded_file($_FILES['doc_archivo']['tmp_name'], $destino)) {
            $stmt = $pdo->prepare("INSERT INTO documentos_empleado (id_empleado_reloj, titulo, nombre_archivo, tipo_archivo) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id_reloj, $titulo, $nombre_archivo, $ext]);
            $mensaje = "📄 Documento agregado."; $tipo_msg = "success";
        }
    }
    if (isset($_POST['borrar_doc_id'])) {
        $id_doc = $_POST['borrar_doc_id'];
        $stmt = $pdo->prepare("SELECT nombre_archivo FROM documentos_empleado WHERE id = ?");
        $stmt->execute([$id_doc]);
        $doc = $stmt->fetch();
        if ($doc) {
            @unlink("uploads/docs/" . $doc['nombre_archivo']);
            $pdo->prepare("DELETE FROM documentos_empleado WHERE id = ?")->execute([$id_doc]);
            $mensaje = "🗑 Documento eliminado."; $tipo_msg = "yellow";
        }
    }
}

// --- SOLICITUD CARNET ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitar_carnet'])) {
    try {
        $stmt = $pdo->prepare("UPDATE empleados SET solicitud_carnet = 1 WHERE id_reloj = ?");
        $stmt->execute([$id_reloj]);
        $mensaje = "✅ Solicitud enviada."; $tipo_msg = "success";
    } catch (Exception $e) { $mensaje = "❌ Error: " . $e->getMessage(); $tipo_msg = "error"; }
}

// --- LEER DATOS ACTUALES ---
$stmt = $pdo->prepare("SELECT * FROM empleados WHERE id_reloj = ?");
$stmt->execute([$id_reloj]);
$emp = $stmt->fetch();
if (!$emp) die("Empleado no encontrado");

// --- LOGICA ESTATUS NOMINA ---
$estatus_db = trim($emp['estatus_nomina'] ?? ''); 
$es_fuera_nomina = (stripos($estatus_db, 'Fuera') !== false); 
$es_en_nomina = !$es_fuera_nomina; 

// --- CÁLCULO VISUALES ---
$hora_entrada = $emp['horario_entrada'] ?: '09:00:00';
$hora_salida  = $emp['horario_salida'] ?: '18:00:00';
$jornada_establecida_txt = "--";
if ($emp['horario_entrada'] && $emp['horario_salida']) {
    $t1 = strtotime($emp['horario_entrada']); $t2 = strtotime($emp['horario_salida']);
    $diff = $t2 - $t1; if ($diff < 0) $diff += 86400;
    $jornada_establecida_txt = floor($diff / 3600) . "h " . floor(($diff % 3600) / 60) . "m";
}

// --- PROMEDIOS ---
$avg_entrada = '--'; $avg_salida = '--'; $avg_jornada = '--';
try {
    $dias_calculo = 15;
    $fecha_ini_calc = date('Y-m-d', strtotime("-$dias_calculo days"));
    $fecha_fin_calc = date('Y-m-d');
    $feriados_rango = []; try { $feriados_rango = $pdo->query("SELECT fecha FROM feriados WHERE fecha BETWEEN '$fecha_ini_calc' AND '$fecha_fin_calc'")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e) {}
    $stmtStats = $pdo->prepare("SELECT fecha, hora_entrada, hora_salida FROM asistencia WHERE id_empleado_reloj = ? AND fecha BETWEEN ? AND ?");
    $stmtStats->execute([$id_reloj, $fecha_ini_calc, $fecha_fin_calc]);
    $asistencias_stats = $stmtStats->fetchAll();
    $sum_in = 0; $cnt_in = 0; $sum_out = 0; $cnt_out = 0; $sum_dur = 0; $cnt_dur = 0;
    foreach ($asistencias_stats as $reg) {
        if (in_array($reg['fecha'], $feriados_rango)) continue;
        if (!empty($reg['hora_entrada']) && $reg['hora_entrada'] != '00:00:00') { $parts = explode(':', $reg['hora_entrada']); $sum_in += ($parts[0] * 3600) + ($parts[1] * 60) + ($parts[2] ?? 0); $cnt_in++; }
        if (!empty($reg['hora_salida']) && $reg['hora_salida'] != '00:00:00') { $parts = explode(':', $reg['hora_salida']); $sum_out += ($parts[0] * 3600) + ($parts[1] * 60) + ($parts[2] ?? 0); $cnt_out++; }
        if (!empty($reg['hora_entrada']) && !empty($reg['hora_salida']) && $reg['hora_salida'] != '00:00:00') { $t_in = strtotime($reg['hora_entrada']); $t_out = strtotime($reg['hora_salida']); if ($t_out > $t_in) { $sum_dur += ($t_out - $t_in); $cnt_dur++; } }
    }
    if ($cnt_in > 0) $avg_entrada = date('g:i A', strtotime('today midnight') + (int)($sum_in / $cnt_in));
    if ($cnt_out > 0) $avg_salida = date('g:i A', strtotime('today midnight') + (int)($sum_out / $cnt_out));
    if ($cnt_dur > 0) { $sec = (int)($sum_dur / $cnt_dur); $avg_jornada = floor($sec / 3600) . "h " . floor(($sec % 3600) / 60) . "m"; }
} catch (Exception $e) {}

// --- PUNTUACIÓN (LÓGICA UNIFICADA) ---
$puntuacion_calculada = 100; $estrellas = 5; $color_puntos = "text-green-600";
try {
    $fecha_ini_score = date('Y-m-d', strtotime('-30 days'));
    $stmtScore = $pdo->prepare("SELECT * FROM asistencia WHERE id_empleado_reloj = ? AND fecha BETWEEN ? AND ?");
    $stmtScore->execute([$id_reloj, $fecha_ini_score, $fecha_fin_calc]);
    $asist_score = $stmtScore->fetchAll();
    $map_asist = []; foreach($asist_score as $r) $map_asist[$r['fecha']] = $r;
    $suma_puntos = 0; $dias_evaluados = 0;
    $curr = strtotime($fecha_fin_calc); $stop = strtotime($fecha_ini_score);
    while($curr >= $stop) {
        $f_str = date('Y-m-d', $curr); $dw = date('w', $curr);
        if ($dw != 0 && $dw != 6 && !in_array($f_str, $feriados_rango)) {
            $puntos_dia = 100; $row = $map_asist[$f_str] ?? null;
            if (!$row || empty($row['hora_entrada'])) $puntos_dia = 0; elseif (empty($row['hora_salida']) || $row['hora_salida'] == '00:00:00') $puntos_dia = 60;
            $suma_puntos += $puntos_dia; $dias_evaluados++;
        }
        $curr = strtotime('-1 day', $curr);
    }
    if ($dias_evaluados > 0) $puntuacion_calculada = round($suma_puntos / $dias_evaluados);
    $estrellas = round($puntuacion_calculada / 20);
    if($puntuacion_calculada < 80) $color_puntos = "text-yellow-500"; if($puntuacion_calculada < 50) $color_puntos = "text-red-500";
} catch(Exception $e){}

// --- CÁLCULO ANTIGÜEDAD MEJORADO ---
$antiguedad_texto = "Sin fecha";
if (!empty($emp['fecha_ingreso'])) {
    $ingreso = new DateTime($emp['fecha_ingreso']);
    $ahora = new DateTime();
    $diff = $ingreso->diff($ahora);
    $partes = [];
    if ($diff->y > 0) $partes[] = $diff->y . " año" . ($diff->y > 1 ? 's' : '');
    if ($diff->m > 0) $partes[] = $diff->m . " mes" . ($diff->m > 1 ? 'es' : '');
    if ($diff->d > 0 && empty($partes)) $partes[] = $diff->d . " día" . ($diff->d > 1 ? 's' : '');
    
    if (empty($partes)) $antiguedad_texto = "Recién ingresado";
    else $antiguedad_texto = implode(" y ", array_slice($partes, 0, 2));
}

$hoy = date('Y-m-d');
$en_vacaciones = ($emp['vacaciones_inicio'] && $emp['vacaciones_fin'] && $hoy >= $emp['vacaciones_inicio'] && $hoy <= $emp['vacaciones_fin']);
$en_licencia = ($emp['licencia_inicio'] && $emp['licencia_fin'] && $hoy >= $emp['licencia_inicio'] && $hoy <= $emp['licencia_fin']);
$docs = $pdo->prepare("SELECT * FROM documentos_empleado WHERE id_empleado_reloj = ? ORDER BY fecha_subida DESC");
$docs->execute([$id_reloj]); $lista_docs = $docs->fetchAll();
$ced_limpia = preg_replace('/[^0-9]/', '', $emp['cedula']);
$foto_perfil_url = file_exists("fotos/$ced_limpia.jpg") ? "fotos/$ced_limpia.jpg?v=".time() : "https://ui-avatars.com/api/?name=".urlencode($emp['nombre_completo']);
$path_cedula = "fotos_cedula/$ced_limpia.jpg"; $existe_cedula = file_exists($path_cedula); $url_cedula = $existe_cedula ? $path_cedula . "?v=".time() : null;
$carnet_jpg_path = "carnets_generados/" . $ced_limpia . "-front.jpg"; $carnet_existe = file_exists($carnet_jpg_path);
$token_qr = md5($id_reloj . "secret_salt" . date('Y-m-d'));
$base_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']);
$url_movil_base = $base_url . "/subir_foto_movil.php?id=$id_reloj&token=$token_qr";

require 'layout_head.php';
?>

<script>
    function mostrarModalQR(tipo) {
        const baseUrl = "<?php echo $url_movil_base; ?>";
        const finalUrl = baseUrl + "&type=" + tipo;
        document.getElementById('qr-image').src = "https://api.qrserver.com/v1/create-qr-code/?size=450x450&data=" + encodeURIComponent(finalUrl);
        document.getElementById('modal-qr').classList.remove('hidden');
    }
    function ocultarModalQR() { document.getElementById('modal-qr').classList.add('hidden'); window.location.reload(); }
    function setHorarioEstandar() { document.getElementById('h_entrada').value = "09:00"; document.getElementById('h_salida').value = "18:00"; document.getElementById('chk_verificado').checked = true; }
    function triggerUploadFoto() { document.getElementById('upload_photo').click(); }
</script>

<div id="modal-qr" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white p-6 rounded-xl shadow-2xl max-w-md w-full text-center relative">
        <button onclick="ocultarModalQR()" class="absolute top-2 right-3 text-gray-400 hover:text-gray-600 text-xl font-bold">&times;</button>
        <h3 class="text-xl font-bold text-gray-700 mb-2">Escáner Móvil</h3>
        <p class="text-xs text-gray-500 mb-4">Usa la cámara de tu celular para subir la foto/documento.</p>
        <img id="qr-image" src="" class="mx-auto border border-gray-200 p-2 rounded-lg mb-4 w-64 h-64 object-contain">
        <button onclick="ocultarModalQR()" class="mt-4 bg-gray-800 text-white w-full py-2 rounded-lg font-bold text-sm">Cerrar y Recargar</button>
    </div>
</div>

<div class="container mx-auto px-4 pb-12 max-w-6xl">
    
    <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold text-gray-800"><i class="fas fa-folder-open mr-2 text-blue-600"></i>Expediente</h1>
        </div>

        <div class="flex-grow max-w-md mx-4">
            <form action="editar_empleado.php" method="GET" class="relative">
                <input type="text" name="q" placeholder="Buscar: Nombre, ID, Cédula..." class="w-full border border-gray-300 rounded-lg pl-3 pr-10 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none shadow-sm transition">
                <button type="submit" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-blue-600">
                    <i class="fas fa-search"></i>
                </button>
            </form>
        </div>

        <a href="perfil_empleado.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg font-bold text-sm transition flex items-center gap-2 whitespace-nowrap">
            <i class="fas fa-arrow-left"></i> Volver a Lista
        </a>
    </div>

    <?php if($mensaje): ?>
        <div class="mb-6 p-4 rounded-lg border shadow-sm <?php echo $tipo_msg=='success'?'bg-green-100 border-green-400 text-green-800':($tipo_msg=='yellow'?'bg-yellow-100 border-yellow-400 text-yellow-800':'bg-red-100 border-red-400 text-red-800'); ?>">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="mainForm">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <div class="space-y-6">
            
            <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200 text-center relative overflow-hidden">
                <div class="absolute top-4 right-4 z-20">
                    <?php if($es_fuera_nomina): ?>
                        <span class="bg-red-100 text-red-700 border border-red-200 text-[10px] font-bold px-3 py-1 rounded-full uppercase shadow-sm"><i class="fas fa-ban mr-1"></i> Inactivo</span>
                    <?php else: ?>
                        <span class="bg-green-100 text-green-700 border border-green-200 text-[10px] font-bold px-3 py-1 rounded-full uppercase shadow-sm"><i class="fas fa-check-circle mr-1"></i> Activo</span>
                    <?php endif; ?>
                </div>

                <div class="relative w-full flex justify-center items-center mb-4 mt-6">
                    <button type="button" onclick="mostrarModalQR('perfil')" class="absolute left-4 md:left-8 top-1/2 transform -translate-y-1/2 bg-white text-green-600 hover:text-green-800 w-10 h-10 rounded-full shadow border flex items-center justify-center transition z-10" title="Cámara Móvil"><i class="fas fa-qrcode text-lg"></i></button>
                    <div class="relative group w-48 h-48">
                        <img src="<?php echo $foto_perfil_url; ?>" class="w-full h-full rounded-full object-cover border-4 border-gray-100 shadow-xl cursor-pointer hover:opacity-95 transition" onclick="triggerUploadFoto()" title="Clic para subir foto">
                        <?php if($en_vacaciones): ?><div class="absolute bottom-4 right-4 z-10 bg-purple-500 text-white text-xs font-bold px-3 py-1 rounded-full shadow animate-bounce"><i class="fas fa-umbrella-beach"></i> Vacaciones</div><?php endif; ?>
                    </div>
                    <button type="button" onclick="triggerUploadFoto()" class="absolute right-4 md:right-8 top-1/2 transform -translate-y-1/2 bg-white text-blue-600 hover:text-blue-800 w-10 h-10 rounded-full shadow border flex items-center justify-center transition z-10" title="Subir desde PC"><i class="fas fa-upload text-lg"></i></button>
                </div>
                
                <h2 class="mt-2 text-2xl font-bold text-gray-800 leading-tight"><?php echo htmlspecialchars($emp['nombre_completo']); ?></h2>
                <p class="text-gray-500 text-sm font-semibold uppercase tracking-wide mb-3"><?php echo htmlspecialchars($emp['cargo']); ?></p>

                <div class="mb-4">
                    <span class="inline-block bg-green-50 text-green-700 border border-green-200 px-4 py-1 rounded-full text-sm font-bold shadow-sm">
                        <i class="fas fa-hourglass-half mr-2"></i> <?php echo $antiguedad_texto; ?>
                    </span>
                </div>

                <div class="flex items-center justify-center gap-1 mb-4" title="Puntuación últimos 30 días">
                    <?php for($i=1; $i<=5; $i++): ?>
                        <i class="fas fa-star <?php echo ($i <= $estrellas) ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                    <?php endfor; ?>
                    <span class="ml-2 font-bold <?php echo $color_puntos; ?> bg-gray-50 px-2 rounded-full border border-gray-100 text-xs"><?php echo $puntuacion_calculada; ?>/100</span>
                </div>

                <div class="flex gap-2 justify-center mt-2">
                    <a href="historial.php?id=<?php echo $id_reloj; ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-xs font-bold shadow transition"><i class="fas fa-calendar-alt mr-1"></i> Ver Asistencia</a>
                </div>
                <input type="file" name="nueva_foto" id="upload_photo" class="hidden" accept="image/*" onchange="document.getElementById('mainForm').submit();">
                <input type="hidden" name="cedula_hidden" value="<?php echo $emp['cedula']; ?>">
            </div>

            <div class="bg-white p-4 rounded-xl shadow border border-gray-200">
                <div class="flex items-center justify-between mb-3 border-b pb-2">
                    <h3 class="font-bold text-gray-700 text-sm flex items-center"><i class="fas fa-id-badge mr-2 text-blue-500"></i> Carnet</h3>
                    <?php 
                        $estado_txt = "No Solicitado"; $estado_col = "bg-gray-100 text-gray-500";
                        if ($emp['solicitud_carnet'] == 1) { $estado_txt = "Solicitado"; $estado_col = "bg-orange-100 text-orange-700"; }
                        if ($emp['solicitud_carnet'] == 2) { $estado_txt = "Listo"; $estado_col = "bg-green-100 text-green-700"; }
                    ?>
                    <span class="px-2 py-0.5 rounded text-[10px] uppercase font-bold <?php echo $estado_col; ?>"><?php echo $estado_txt; ?></span>
                </div>
                <?php if($carnet_existe): ?>
                    <div class="mb-3 bg-gray-100 rounded-lg overflow-hidden border border-gray-300 shadow-inner">
                        <img src="<?php echo $carnet_jpg_path . '?v='.time(); ?>" class="w-full h-auto block" alt="Carnet">
                    </div>
                <?php else: ?>
                    <div class="text-center py-6 bg-gray-50 rounded border border-dashed border-gray-300 mb-3"><i class="fas fa-image text-gray-300 text-3xl mb-2"></i><p class="text-xs text-gray-400">Sin carnet generado</p></div>
                <?php endif; ?>
                
                <div class="grid grid-cols-1 gap-2">
                    <?php if ($emp['solicitud_carnet'] != 1): ?>
                        <button type="submit" name="solicitar_carnet" value="1" class="w-full bg-orange-500 hover:bg-orange-600 text-white text-center py-2 rounded text-xs font-bold shadow transition"><i class="fas fa-paper-plane mr-1"></i> Solicitar Diseño</button>
                    <?php else: ?>
                         <div class="w-full bg-orange-50 border border-orange-200 text-orange-600 text-center py-2 rounded text-xs font-bold shadow-sm"><i class="fas fa-clock mr-1"></i> Solicitud Pendiente</div>
                    <?php endif; ?>
                    <a href="carnets_diseno.php?id=<?php echo $id_reloj; ?>" class="block w-full bg-blue-600 hover:bg-blue-700 text-white text-center py-2 rounded text-xs font-bold shadow transition"><i class="fas fa-print mr-1"></i> Diseñar / Imprimir</a>
                </div>
            </div>

            <div class="bg-white p-4 rounded-xl shadow border border-gray-200">
                <div class="flex justify-between items-center mb-3 border-b pb-2">
                    <h3 class="font-bold text-gray-700 text-sm"><i class="far fa-id-card mr-2 text-blue-500"></i> Documento de Identidad</h3>
                    <button type="button" onclick="mostrarModalQR('cedula')" class="text-[10px] bg-green-100 text-green-700 px-2 py-1 rounded hover:bg-green-200 font-bold"><i class="fas fa-qrcode mr-1"></i> Subir</button>
                </div>
                <?php if($existe_cedula): ?>
                    <div class="mb-3 rounded-lg overflow-hidden border border-gray-300 shadow-inner group relative">
                        <img src="<?php echo $url_cedula; ?>" class="w-full h-40 object-cover">
                        <div class="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 transition duration-300">
                            <a href="<?php echo $url_cedula; ?>" class="bg-white text-gray-800 px-3 py-1 rounded text-xs font-bold"><i class="fas fa-eye"></i> Ver</a>
                            <a href="<?php echo $url_cedula; ?>" download class="bg-blue-600 text-white px-3 py-1 rounded text-xs font-bold"><i class="fas fa-download"></i></a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-gray-50 border-2 border-dashed border-gray-300 rounded-lg p-6 text-center text-gray-400 mb-3">
                        <i class="far fa-image text-3xl mb-2"></i>
                        <p class="text-xs">No se ha subido la foto de la cédula.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="lg:col-span-2 space-y-6">
            
            <div class="bg-white p-6 rounded-xl shadow border border-gray-200 relative">
                <div class="flex justify-between items-center mb-4 border-b pb-2">
                    <h2 class="text-lg font-bold text-gray-700">Información Personal</h2>
                    <button type="submit" name="guardar_datos" value="1" class="ml-4 bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded shadow font-bold text-xs"><i class="fas fa-save mr-1"></i> Guardar Todo</button>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div><label class="block text-xs font-bold text-gray-500 mb-1">Nombre Completo</label><input type="text" name="nombre" value="<?php echo htmlspecialchars($emp['nombre_completo']); ?>" class="w-full border rounded p-2 text-sm uppercase"></div>
                    <div><label class="block text-xs font-bold text-gray-500 mb-1">Cédula</label><input type="text" name="cedula" value="<?php echo htmlspecialchars($emp['cedula']); ?>" class="w-full border rounded p-2 text-sm"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div><label class="block text-xs font-bold text-gray-500 mb-1">Teléfono</label><input type="text" name="telefono" value="<?php echo htmlspecialchars($emp['telefono'] ?? ''); ?>" class="w-full border rounded p-2 text-sm"></div>
                    <div><label class="block text-xs font-bold text-gray-500 mb-1">Departamento</label><input type="text" name="departamento" value="<?php echo htmlspecialchars($emp['departamento']); ?>" class="w-full border rounded p-2 text-sm uppercase"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div><label class="block text-xs font-bold text-gray-500 mb-1">Cargo</label><input type="text" name="cargo" value="<?php echo htmlspecialchars($emp['cargo']); ?>" class="w-full border rounded p-2 text-sm uppercase"></div>
                    <div><label class="block text-xs font-bold text-gray-500 mb-1">Tipo</label><select name="tipo_personal" class="w-full border rounded p-2 text-sm bg-white"><option value="planta" <?php echo $emp['tipo_personal']=='planta'?'selected':''; ?>>PLANTA</option><option value="externo" <?php echo $emp['tipo_personal']=='externo'?'selected':''; ?>>EXTERNO</option></select></div>
                    
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Fecha Ingreso</label>
                        <input type="date" name="fecha_ingreso" value="<?php echo $emp['fecha_ingreso']; ?>" class="w-full border rounded p-2 text-sm mb-1">
                    </div>
                </div>

                <div class="mb-4 bg-gray-50 p-4 rounded border border-gray-200">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Estatus en Nómina (RRHH)</label>
                    <div class="relative">
                        <select name="estatus_nomina" class="w-full border border-gray-300 rounded p-2 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none">
                            <option value="En Nomina" <?php echo $es_en_nomina ? 'selected' : ''; ?>>✅ En Nómina Activa</option>
                            <option value="Fuera de Nomina" <?php echo $es_fuera_nomina ? 'selected' : ''; ?>>🚫 Fuera de Nómina / Inactivo</option>
                        </select>
                    </div>
                    <p class="text-[10px] text-gray-500 mt-1">Valor actual en base de datos: <b>"<?php echo htmlspecialchars($emp['estatus_nomina'] ?? 'N/A'); ?>"</b></p>
                </div>

                <div class="mb-4 bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <div class="flex justify-between items-center mb-3"><label class="font-bold text-sm text-gray-700">Horario Laboral</label><button type="button" onclick="setHorarioEstandar()" class="text-xs text-blue-600 hover:underline font-bold">Usar 9am - 6pm</button></div>
                    <div class="flex gap-2 items-center mb-3">
                        <div class="w-1/3"><span class="text-xs text-gray-500 block mb-1">Entrada</span><input type="time" name="horario_entrada" id="h_entrada" value="<?php echo $hora_entrada; ?>" class="w-full border rounded p-2 bg-white text-sm"></div>
                        <div class="w-1/3"><span class="text-xs text-gray-500 block mb-1">Salida</span><input type="time" name="horario_salida" id="h_salida" value="<?php echo $hora_salida; ?>" class="w-full border rounded p-2 bg-white text-sm"></div>
                        <div class="w-1/3 text-center">
                            <span class="text-xs block text-gray-500 mb-1">Jornada</span>
                            <div class="bg-white border rounded p-2 text-sm font-bold text-gray-700"><?php echo $jornada_establecida_txt; ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 mt-2"><input type="checkbox" name="horario_verificado" id="chk_verificado" value="1" <?php echo ($emp['horario_verificado']) ? 'checked' : ''; ?>><label for="chk_verificado" class="text-xs font-bold text-gray-600">Verificado</label></div>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-500 mb-1">Promedio de Métricas Recientes (Últimos 15 días)</label>
                    <div class="flex gap-2 text-center text-[11px]">
                        <div class="bg-blue-50 px-3 py-2 rounded border border-blue-100 text-blue-800 flex-grow"><div class="font-bold uppercase">Entrada</div><div class="font-mono text-lg"><?php echo $avg_entrada; ?></div></div>
                        <div class="bg-purple-50 px-3 py-2 rounded border border-purple-100 text-purple-800 flex-grow"><div class="font-bold uppercase">Salida</div><div class="font-mono text-lg"><?php echo $avg_salida; ?></div></div>
                        <div class="bg-green-50 px-3 py-2 rounded border border-green-100 text-green-800 flex-grow"><div class="font-bold uppercase">Jornada</div><div class="font-mono text-lg"><?php echo $avg_jornada; ?></div></div>
                    </div>
                </div>

                <div class="mb-2"><label class="block text-xs font-bold text-gray-500 mb-1">Notas Nómina</label><textarea name="notas_nomina" rows="2" class="w-full border rounded p-2 text-sm bg-yellow-50 border-yellow-200"><?php echo htmlspecialchars($emp['notas_nomina']); ?></textarea></div>
            </div>

            <div class="bg-white p-6 rounded-xl shadow border-l-4 border-indigo-500">
                <h2 class="text-lg font-bold text-gray-700 mb-4 pb-2 border-b flex items-center gap-2"><i class="fas fa-business-time text-indigo-500"></i> Gestión de Ausencias</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-purple-50 p-4 rounded-lg border border-purple-200">
                        <div class="flex justify-between items-center mb-3"><label class="font-bold text-sm text-purple-800"><i class="fas fa-umbrella-beach mr-1"></i> Vacaciones</label><?php if($en_vacaciones): ?><span class="bg-purple-200 text-purple-800 text-[10px] font-bold px-2 py-1 rounded">ACTIVO AHORA</span><?php endif; ?></div>
                        <div class="space-y-3">
                            <div><span class="text-xs text-gray-500 block mb-1">Inicio</span><input type="date" name="vacaciones_inicio" value="<?php echo $emp['vacaciones_inicio']; ?>" class="w-full border border-purple-200 rounded p-2 bg-white text-sm"></div>
                            <div><span class="text-xs text-gray-500 block mb-1">Fin</span><input type="date" name="vacaciones_fin" value="<?php echo $emp['vacaciones_fin']; ?>" class="w-full border border-purple-200 rounded p-2 bg-white text-sm"></div>
                        </div>
                    </div>
                    <div class="bg-red-50 p-4 rounded-lg border border-red-200">
                        <div class="flex justify-between items-center mb-3"><label class="font-bold text-sm text-red-800"><i class="fas fa-notes-medical mr-1"></i> Licencia Médica</label><?php if($en_licencia): ?><span class="bg-red-200 text-red-800 text-[10px] font-bold px-2 py-1 rounded">ACTIVO AHORA</span><?php endif; ?></div>
                        <div class="space-y-3">
                            <div class="grid grid-cols-2 gap-2">
                                <div><span class="text-xs text-gray-500 block mb-1">Inicio</span><input type="date" name="licencia_inicio" value="<?php echo $emp['licencia_inicio']; ?>" class="w-full border border-red-200 rounded p-2 bg-white text-sm"></div>
                                <div><span class="text-xs text-gray-500 block mb-1">Fin</span><input type="date" name="licencia_fin" value="<?php echo $emp['licencia_fin']; ?>" class="w-full border border-red-200 rounded p-2 bg-white text-sm"></div>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" onclick="mostrarModalQR('doc')" class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded text-xs font-bold shadow flex items-center gap-1"><i class="fas fa-qrcode"></i> Subir Doc</button>
                                <button type="button" onclick="scrollToDocs()" class="bg-white border border-red-300 text-red-600 hover:bg-red-50 px-3 py-2 rounded text-xs font-bold shadow-sm"><i class="fas fa-paperclip"></i> Anexar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="sec_documentos" class="bg-white p-6 rounded-xl shadow border border-gray-200">
                <div class="flex justify-between items-center mb-4 border-b pb-2">
                    <h2 class="text-lg font-bold text-gray-700"><i class="fas fa-file-alt mr-2 text-slate-500"></i>Documentos y Licencias</h2>
                    <button type="button" onclick="mostrarModalQR('doc')" class="text-green-600 hover:text-green-800 text-sm font-bold flex items-center" title="Escanear con Móvil"><i class="fas fa-qrcode mr-1"></i> Subir con Celular</button>
                </div>
                <div class="mb-6 bg-slate-50 p-4 rounded border border-slate-200">
                    <div class="flex gap-3 items-end">
                        <div class="flex-grow">
                            <label class="block text-xs font-bold text-gray-500 mb-1">Descripción</label>
                            <input type="text" name="doc_titulo" placeholder="Ej: Licencia Médica Junio" class="w-full border rounded p-2 text-sm">
                        </div>
                        <div><input type="file" name="doc_archivo" class="text-xs w-full mb-1"></div>
                        <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white px-4 py-2 rounded text-sm font-bold shadow"><i class="fas fa-upload mr-1"></i> Subir</button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                            <tr><th class="px-4 py-2 text-left">Documento</th><th class="px-4 py-2 text-center">Tipo</th><th class="px-4 py-2 text-center">Fecha</th><th class="px-4 py-2 text-right">Acciones</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if(empty($lista_docs)): ?><tr><td colspan="4" class="p-4 text-center text-gray-400">No hay documentos adjuntos.</td></tr><?php else: foreach($lista_docs as $doc): $ext = strtolower($doc['tipo_archivo']); $icono = in_array($ext, ['jpg','png','jpeg']) ? 'fa-file-image text-blue-400' : 'fa-file-pdf text-red-400'; ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2 font-medium text-gray-700 flex items-center gap-2"><i class="fas <?php echo $icono; ?>"></i><?php echo htmlspecialchars($doc['titulo']); ?></td>
                                <td class="px-4 py-2 text-center text-xs uppercase text-gray-500"><?php echo $ext; ?></td>
                                <td class="px-4 py-2 text-center text-xs font-mono text-gray-400"><?php echo date('d/m/Y', strtotime($doc['fecha_subida'])); ?></td>
                                <td class="px-4 py-2 text-right">
                                    <a href="uploads/docs/<?php echo $doc['nombre_archivo']; ?>" class="text-blue-600 hover:text-blue-800 mr-3"><i class="fas fa-eye"></i></a>
                                    <button type="submit" name="borrar_doc_id" value="<?php echo $doc['id']; ?>" class="text-red-500 hover:text-red-700" onclick="return confirm('¿Eliminar?');"><i class="fas fa-trash-alt"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    </form>
</div>
<?php require 'layout_footer.php'; ?>