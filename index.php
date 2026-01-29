<?php
// index.php - DASHBOARD FINAL: FILTROS AVANZADOS Y ORDENAMIENTO
require 'auth.php'; 
verificarPermiso(['admin', 'rrhh']); 

// --- CONEXIÓN BD ---
if (!isset($pdo)) {
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
}

// --- CONFIGURACIÓN ---
$hoy = date('Y-m-d');
$ayer = date('Y-m-d', strtotime('-1 day'));
$fecha_inicio_stats = date('Y-m-d', strtotime('-30 days'));

// Última actualización
$ultimo_evento_db = "Sin registros";
try {
    $rowLast = $pdo->query("SELECT fecha, hora_salida FROM asistencia ORDER BY fecha DESC, hora_salida DESC LIMIT 1")->fetch();
    if ($rowLast) $ultimo_evento_db = date('d/m/Y h:i:s A', strtotime($rowLast['fecha'] . ' ' . $rowLast['hora_salida']));
} catch (Exception $e) {}

$mensaje_accion = '';

// --- LÓGICA DE FUSIÓN (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fusionar_seleccionados'])) {
    if (!empty($_POST['ids_to_merge'])) {
        $count_merged = 0;
        try {
            $pdo->beginTransaction();
            foreach ($_POST['ids_to_merge'] as $ids_str) {
                $ids = array_map('trim', explode(',', $ids_str));
                $ids = array_filter($ids, 'is_numeric');
                if (count($ids) < 2) continue;
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmtCheck = $pdo->prepare("SELECT id_reloj FROM empleados WHERE id_reloj IN ($placeholders) ORDER BY id_reloj ASC");
                $stmtCheck->execute($ids);
                $valid_ids = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);
                if (count($valid_ids) < 2) continue;
                $target_id = $valid_ids[0]; $source_ids = array_slice($valid_ids, 1);
                $placeholders_src = implode(',', array_fill(0, count($source_ids), '?'));
                $pdo->prepare("UPDATE IGNORE asistencia SET id_empleado_reloj = ? WHERE id_empleado_reloj IN ($placeholders_src)")->execute(array_merge([$target_id], $source_ids));
                $pdo->prepare("DELETE FROM asistencia WHERE id_empleado_reloj IN ($placeholders_src)")->execute($source_ids);
                $pdo->prepare("DELETE FROM empleados WHERE id_reloj IN ($placeholders_src)")->execute($source_ids);
                $count_merged++;
            }
            $pdo->commit();
            $mensaje_accion = "✅ Se fusionaron <b>$count_merged</b> grupos.";
        } catch (Exception $e) { $pdo->rollBack(); $mensaje_accion = "❌ Error: " . $e->getMessage(); }
    }
}

// --- ACCIÓN: JUSTIFICAR AUSENCIA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['justificar_ausencia'])) {
    $id_emp_just = $_POST['id_justificar'];
    $motivo_just = trim($_POST['motivo_justificacion']);
    if ($id_emp_just && $motivo_just) {
        try {
            $pdo->prepare("INSERT INTO excepciones_asistencia (id_empleado_reloj, tipo, fecha_inicio, fecha_fin, motivo) VALUES (?, 'justificado', ?, ?, ?)")
                ->execute([$id_emp_just, date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), $motivo_just]);
            $mensaje_accion = "✅ Ausencia justificada.";
        } catch (Exception $e) { $mensaje_accion = "❌ Error: " . $e->getMessage(); }
    }
}

// --- LOGICA DE VISTA ---
$vista = isset($_GET['vista']) ? $_GET['vista'] : 'hoy'; 

// Filtros Generales
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$filtro_nomina_activo = (!isset($_GET['filtrar']) || isset($_GET['en_nomina']));
$filtro_verificados_activo = isset($_GET['verificados']);
$filtro_estado_llegada = isset($_GET['estado_llegada']) ? $_GET['estado_llegada'] : ''; // Nuevo filtro
$orden_jornada = isset($_GET['orden_jornada']) ? $_GET['orden_jornada'] : ''; // Nuevo orden

$filtro_nombres = " AND e.nombre_completo IS NOT NULL AND TRIM(e.nombre_completo) != '' AND e.nombre_completo NOT LIKE 'INV%' ";
$filtro_cedula_sql = $filtro_nomina_activo ? " AND e.cedula != '' AND e.cedula IS NOT NULL " : "";
$filtro_verificados_sql = $filtro_verificados_activo ? " AND e.horario_verificado = 1 " : "";
$filtros_comunes = $filtro_nombres . $filtro_cedula_sql . $filtro_verificados_sql;


// =================================================================================
// PREPARACIÓN DE DATOS SEGÚN VISTA
// =================================================================================
$datos_tabla = [];

if ($vista === 'ausencias') {
    // === LÓGICA VISTA: AUSENCIAS RECURRENTES ===
    $dias_analisis = 15;
    $fecha_inicio_aus = date('Y-m-d', strtotime("-$dias_analisis days"));
    $fecha_fin_aus = $ayer;

    // Días hábiles
    $feriados_rango = $pdo->query("SELECT fecha FROM feriados WHERE fecha BETWEEN '$fecha_inicio_aus' AND '$fecha_fin_aus'")->fetchAll(PDO::FETCH_COLUMN);
    $dias_habiles_total = 0; $curr = strtotime($fecha_inicio_aus); $end = strtotime($fecha_fin_aus);
    while($curr <= $end) {
        $dw = date('w', $curr);
        if($dw != 0 && $dw != 6 && !in_array(date('Y-m-d', $curr), $feriados_rango)) $dias_habiles_total++;
        $curr = strtotime('+1 day', $curr);
    }

    $sqlAus = "SELECT e.id_reloj, e.nombre_completo, e.cedula, e.departamento, e.cargo, 
                      e.vacaciones_inicio, e.vacaciones_fin, e.licencia_inicio, e.licencia_fin, 
                      COUNT(DISTINCT a.fecha) as dias_trabajados
               FROM empleados e 
               LEFT JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj AND a.fecha BETWEEN '$fecha_inicio_aus' AND '$fecha_fin_aus'
               WHERE e.tipo_personal = 'planta' 
               AND e.cedula != '' 
               AND (e.notas_nomina IS NULL OR e.notas_nomina NOT LIKE '%SUSPENDIDO%')
               $filtro_nombres
               GROUP BY e.id_reloj";
               
    if ($busqueda !== '') $sqlAus .= " HAVING (nombre_completo LIKE '%$busqueda%' OR cedula LIKE '%$busqueda%')";

    $cand_ausencias = $pdo->query($sqlAus)->fetchAll();
    $excepciones_db = $pdo->query("SELECT id_empleado_reloj FROM excepciones_asistencia WHERE tipo IN ('justificado', 'permanente') AND (fecha_fin >= '$fecha_inicio_aus' OR fecha_fin IS NULL)")->fetchAll(PDO::FETCH_COLUMN);

    foreach($cand_ausencias as $emp) {
        if(in_array($emp['id_reloj'], $excepciones_db)) continue;

        $dias_exentos = 0; $curr = strtotime($fecha_inicio_aus); $end = strtotime($fecha_fin_aus);
        while($curr <= $end) {
            $d = date('Y-m-d', $curr); $dw = date('w', $curr);
            if($dw != 0 && $dw != 6 && !in_array($d, $feriados_rango)) {
                $is_vac = ($emp['vacaciones_inicio'] && $d >= $emp['vacaciones_inicio'] && $d <= $emp['vacaciones_fin']);
                $is_lic = ($emp['licencia_inicio'] && $d >= $emp['licencia_inicio'] && $d <= $emp['licencia_fin']);
                if ($is_vac || $is_lic) $dias_exentos++;
            }
            $curr = strtotime('+1 day', $curr);
        }
        $faltas_reales = $dias_habiles_total - $emp['dias_trabajados'] - $dias_exentos;
        if($faltas_reales >= 3) {
            $emp['faltas_calc'] = $faltas_reales;
            $datos_tabla[] = $emp;
        }
    }
    usort($datos_tabla, function($a, $b) { return $b['faltas_calc'] - $a['faltas_calc']; });

} else {
    // === LÓGICA VISTA: ENTRADAS (HOY) O SALIDAS (AYER) ===
    $fecha_monitor = ($vista === 'ayer') ? $ayer : $hoy;
    
    $sqlListado = "SELECT 
                    e.id_reloj, e.nombre_completo, e.cedula, e.departamento, e.cargo,
                    e.horario_entrada, e.horario_salida, e.horario_verificado,
                    a.hora_entrada as registro_entrada, 
                    a.hora_salida as registro_salida
                   FROM empleados e
                   LEFT JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj AND a.fecha = :fecha_monitor
                   WHERE 1=1 " . $filtros_comunes;

    if ($busqueda !== '') {
        $sqlListado .= " AND (e.nombre_completo LIKE :b OR e.cedula LIKE :b OR e.departamento LIKE :b) ";
    }

    // --- FILTRO DE ESTADO DE LLEGADA (SOLO HOY) ---
    if ($vista === 'hoy' && $filtro_estado_llegada) {
        // Horario por defecto si es null: 09:00:00
        $col_horario = "IFNULL(e.horario_entrada, '09:00:00')";
        
        if ($filtro_estado_llegada === 'tiempo') {
            // A tiempo: Entrada <= Horario + 15 min
            $sqlListado .= " AND a.hora_entrada IS NOT NULL AND a.hora_entrada <= ADDTIME($col_horario, '00:15:00') ";
        } elseif ($filtro_estado_llegada === 'med_tarde') {
            // Med. Tarde: Entre 16 y 30 min despues
            $sqlListado .= " AND a.hora_entrada > ADDTIME($col_horario, '00:15:00') AND a.hora_entrada <= ADDTIME($col_horario, '00:30:00') ";
        } elseif ($filtro_estado_llegada === 'tarde') {
            // Tarde: > 30 min despues
            $sqlListado .= " AND a.hora_entrada > ADDTIME($col_horario, '00:30:00') ";
        }
    }

    // --- ORDENAMIENTO ---
    if ($vista === 'ayer') {
        // Orden por Jornada (si aplica)
        if ($orden_jornada === 'asc') {
            $sqlListado .= " ORDER BY (a.hora_salida IS NOT NULL AND a.hora_entrada IS NOT NULL) DESC, TIMEDIFF(a.hora_salida, a.hora_entrada) ASC ";
        } elseif ($orden_jornada === 'desc') {
            $sqlListado .= " ORDER BY TIMEDIFF(a.hora_salida, a.hora_entrada) DESC ";
        } else {
            // Default
            $sqlListado .= " ORDER BY (a.hora_salida IS NOT NULL AND a.hora_salida != '00:00:00') DESC, a.hora_salida DESC, e.nombre_completo ASC";
        }
    } else {
        // Hoy
        $sqlListado .= " ORDER BY (a.hora_entrada IS NOT NULL) DESC, a.hora_entrada DESC, e.nombre_completo ASC";
    }

    $stmtList = $pdo->prepare($sqlListado);
    $paramsList = [':fecha_monitor' => $fecha_monitor];
    if ($busqueda !== '') $paramsList[':b'] = "%$busqueda%";
    $stmtList->execute($paramsList);
    $datos_tabla = $stmtList->fetchAll();
}

// --- OTROS DATOS ---
$lista_duplicados = $pdo->query("SELECT 'Duplicado' as tipo, nombre_completo as valor, GROUP_CONCAT(id_reloj) as ids, COUNT(*) as c FROM empleados WHERE nombre_completo NOT LIKE 'INV%' AND nombre_completo != '' GROUP BY nombre_completo HAVING c > 1")->fetchAll();
$all_employees_search = $pdo->query("SELECT id_reloj, nombre_completo FROM empleados WHERE nombre_completo IS NOT NULL AND nombre_completo != '' ORDER BY nombre_completo ASC")->fetchAll();
$ausentes_activos = $pdo->query("SELECT id_reloj, nombre_completo, departamento, 'Vacaciones' as tipo, vacaciones_fin as fecha_fin FROM empleados WHERE '$hoy' BETWEEN vacaciones_inicio AND vacaciones_fin UNION SELECT id_reloj, nombre_completo, departamento, 'Licencia' as tipo, licencia_fin as fecha_fin FROM empleados WHERE '$hoy' BETWEEN licencia_inicio AND licencia_fin ORDER BY fecha_fin ASC")->fetchAll();
$nuevos_ingresos = $pdo->query("SELECT id_reloj, nombre_completo, departamento, fecha_ingreso FROM empleados ORDER BY id_reloj DESC LIMIT 5")->fetchAll();
$bajo_rendimiento = $pdo->query("SELECT e.nombre_completo, COUNT(*) as dias_bajos, e.id_reloj FROM asistencia a JOIN empleados e ON a.id_empleado_reloj = e.id_reloj WHERE a.fecha BETWEEN '$fecha_inicio_stats' AND '$hoy' AND a.hora_entrada IS NOT NULL AND a.hora_salida IS NOT NULL AND (TIME_TO_SEC(a.hora_salida) - TIME_TO_SEC(a.hora_entrada)) / 3600 < 4 AND (TIME_TO_SEC(a.hora_salida) - TIME_TO_SEC(a.hora_entrada)) > 0 $filtros_comunes GROUP BY e.id_reloj ORDER BY dias_bajos DESC LIMIT 5")->fetchAll();
$top_actividad = $pdo->query("SELECT e.nombre_completo, SUM(a.total_eventos) as total_fichajes, e.id_reloj FROM asistencia a JOIN empleados e ON a.id_empleado_reloj = e.id_reloj WHERE a.fecha BETWEEN '$fecha_inicio_stats' AND '$hoy' $filtros_comunes GROUP BY e.id_reloj ORDER BY total_fichajes DESC LIMIT 5")->fetchAll();

// KPI HOY (Siempre visible)
$datos_kpi = $pdo->query("SELECT e.id_reloj, a.hora_entrada, a.hora_salida FROM empleados e LEFT JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj AND a.fecha = '$hoy' WHERE 1=1 $filtros_comunes")->fetchAll();
$presentes = 0; $tardanzas_hoy = 0; $sin_salida = 0; $total_emps = count($datos_kpi);
foreach ($datos_kpi as $d) {
    if (!empty($d['hora_entrada'])) {
        $presentes++;
        if ($d['hora_entrada'] > '08:15:00') $tardanzas_hoy++; 
        if (empty($d['hora_salida']) || $d['hora_entrada'] == $d['hora_salida']) $sin_salida++;
    }
}
$ausentes = $total_emps - $presentes;

require 'layout_head.php'; 
?>

<script>
    function toggleCheckboxes(source) {
        checkboxes = document.getElementsByName('ids_to_merge[]');
        for(var i=0, n=checkboxes.length;i<n;i++) { checkboxes[i].checked = source.checked; }
    }
    function irAPerfilVacaciones(input) {
        var val = input.value;
        var options = document.getElementById('lista_emps_vac').options;
        for (var i = 0; i < options.length; i++) {
            if (options[i].value === val) {
                window.open('editar_empleado.php?id=' + options[i].getAttribute('data-id'), '_blank');
                input.value = ''; break;
            }
        }
    }
</script>

<?php if($mensaje_accion): ?>
    <div class="mb-4 p-3 rounded-lg bg-green-100 border border-green-400 text-green-800 shadow-sm flex items-center text-sm animate-pulse">
        <i class="fas fa-check-circle mr-2"></i><span><?php echo $mensaje_accion; ?></span>
    </div>
<?php endif; ?>

<!-- 1. ENCABEZADO Y KPIs (ARRIBA) -->
<div class="flex flex-col md:flex-row justify-between items-end mb-4 gap-4">
    <div class="bg-white px-4 py-2 rounded-lg shadow-sm border-l-4 border-green-500 w-full md:w-auto">
        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-0.5">Última Actualización</p>
        <p class="text-lg font-black text-green-700 font-mono leading-none flex items-center gap-2"><i class="fas fa-clock opacity-50"></i> <?php echo $ultimo_evento_db; ?></p>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 flex-grow w-full">
        <div class="bg-white p-2 rounded-lg shadow-sm border-b-2 border-green-500 text-center"><p class="text-[10px] text-gray-400 font-bold uppercase">Presentes</p><p class="text-xl font-black text-gray-700 leading-none"><?php echo $presentes; ?></p></div>
        <div class="bg-white p-2 rounded-lg shadow-sm border-b-2 border-red-500 text-center"><p class="text-[10px] text-gray-400 font-bold uppercase">Ausentes</p><p class="text-xl font-black text-red-600 leading-none"><?php echo $ausentes; ?></p></div>
        <div class="bg-white p-2 rounded-lg shadow-sm border-b-2 border-yellow-500 text-center"><p class="text-[10px] text-gray-400 font-bold uppercase">Tarde (>8:15)</p><p class="text-xl font-black text-yellow-600 leading-none"><?php echo $tardanzas_hoy; ?></p></div>
        <div class="bg-white p-2 rounded-lg shadow-sm border-b-2 border-blue-500 text-center"><p class="text-[10px] text-gray-400 font-bold uppercase">Sin Salida</p><p class="text-xl font-black text-blue-600 leading-none"><?php echo $sin_salida; ?></p></div>
    </div>
</div>

<!-- 2. MONITOR DINÁMICO (CENTRO - PRIORIDAD) -->
<div class="bg-white rounded-xl shadow-md border border-gray-200 flex flex-col h-[600px] mb-8">
    
    <!-- HEADER CON PESTAÑAS -->
    <div class="px-4 py-3 border-b bg-gray-50 flex flex-col md:flex-row justify-between items-center gap-3 flex-shrink-0">
        
        <!-- PESTAÑAS (RENOMBRADAS) -->
        <div class="flex bg-gray-200 rounded-lg p-1 gap-1">
            <a href="?vista=hoy&filtrar=1<?php echo $busqueda ? '&busqueda='.$busqueda : ''; ?>" class="<?php echo $vista=='hoy' ? 'bg-white text-blue-700 shadow' : 'text-gray-500 hover:text-gray-700'; ?> px-4 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-2"><i class="fas fa-calendar-day"></i> Entradas de hoy</a>
            <a href="?vista=ayer&filtrar=1<?php echo $busqueda ? '&busqueda='.$busqueda : ''; ?>" class="<?php echo $vista=='ayer' ? 'bg-white text-purple-700 shadow' : 'text-gray-500 hover:text-gray-700'; ?> px-4 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-2"><i class="fas fa-history"></i> Salidas de ayer</a>
            <a href="?vista=ausencias" class="<?php echo $vista=='ausencias' ? 'bg-white text-red-700 shadow' : 'text-gray-500 hover:text-gray-700'; ?> px-4 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-2"><i class="fas fa-exclamation-triangle"></i> Ausencias</a>
        </div>
        
        <!-- BUSCADOR Y FILTROS -->
        <form method="GET" class="flex flex-wrap gap-2 items-center w-full md:w-auto justify-end">
            <input type="hidden" name="vista" value="<?php echo $vista; ?>">
            <input type="hidden" name="filtrar" value="1">
            
            <?php if($vista === 'hoy'): ?>
                <!-- FILTRO ESTADO LLEGADA (SOLO HOY) -->
                <select name="estado_llegada" onchange="this.form.submit()" class="text-xs border rounded px-2 py-1.5 bg-white font-bold text-gray-600 outline-none focus:ring-1 focus:ring-blue-500">
                    <option value="">Todos los Estados</option>
                    <option value="tiempo" <?php echo $filtro_estado_llegada=='tiempo'?'selected':''; ?>>🟢 A tiempo</option>
                    <option value="med_tarde" <?php echo $filtro_estado_llegada=='med_tarde'?'selected':''; ?>>🟡 Med. Tarde</option>
                    <option value="tarde" <?php echo $filtro_estado_llegada=='tarde'?'selected':''; ?>>🔴 Tarde</option>
                </select>
            <?php endif; ?>

            <?php if($vista !== 'ausencias'): ?>
            <div class="flex items-center gap-2 mr-2">
                <label class="flex items-center gap-1 cursor-pointer text-xs bg-white px-2 py-1 rounded border hover:bg-gray-50">
                    <input type="checkbox" name="en_nomina" value="1" onchange="this.form.submit()" <?php echo $filtro_nomina_activo ? 'checked' : ''; ?> class="accent-blue-600"><span class="text-gray-600 font-bold">Nómina</span>
                </label>
                <label class="flex items-center gap-1 cursor-pointer text-xs bg-white px-2 py-1 rounded border hover:bg-gray-50">
                    <input type="checkbox" name="verificados" value="1" onchange="this.form.submit()" <?php echo $filtro_verificados_activo ? 'checked' : ''; ?> class="accent-green-600"><span class="text-gray-600 font-bold">Verificados</span>
                </label>
            </div>
            <?php endif; ?>
            
            <div class="relative">
                <input type="text" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Buscar..." class="pl-7 pr-3 py-1.5 rounded-lg border text-xs w-40 focus:w-60 focus:ring-1 focus:ring-blue-500 transition-all outline-none bg-white">
                <i class="fas fa-search absolute left-2 top-2 text-gray-400 text-xs"></i>
            </div>
        </form>
    </div>

    <!-- TABLA DE DATOS -->
    <div class="overflow-auto flex-grow">
        <table class="min-w-full text-xs">
            <thead class="bg-white text-gray-500 uppercase sticky top-0 shadow-sm z-10 font-bold border-b">
                <tr>
                    <th class="px-4 py-3 text-left w-14">Foto</th>
                    <th class="px-4 py-3 text-left">Empleado</th>
                    <th class="px-4 py-3 text-left">Depto / Cargo</th>
                    
                    <?php if($vista === 'ausencias'): ?>
                        <th class="px-4 py-3 text-center bg-red-50 text-red-800">Faltas (15 días)</th>
                        <th class="px-4 py-3 text-center">Estado</th>
                    <?php elseif($vista === 'hoy'): ?>
                        <th class="px-4 py-3 text-center bg-blue-50">Horario Entrada</th>
                        <th class="px-4 py-3 text-center bg-blue-50">Registro Entrada</th>
                        <th class="px-4 py-3 text-center">Estado Llegada</th>
                    <?php else: // Ayer ?>
                        <th class="px-4 py-3 text-center bg-purple-50">Horario Salida</th>
                        <th class="px-4 py-3 text-center bg-purple-50">Registro Salida</th>
                        <!-- COLUMNA ORDENABLE JORNADA -->
                        <th class="px-4 py-3 text-center bg-green-50 text-green-800 cursor-pointer hover:bg-green-100 transition" onclick="window.location.href='?vista=ayer&filtrar=1&orden_jornada=<?php echo ($orden_jornada=='desc'?'asc':'desc'); ?>&busqueda=<?php echo $busqueda; ?>'">
                            Jornada Total <?php if($orden_jornada) echo ($orden_jornada=='desc' ? '⬇' : '⬆'); ?>
                        </th>
                    <?php endif; ?>
                    
                    <th class="px-4 py-3 text-right">Acción</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if(count($datos_tabla) > 0): ?>
                    <?php foreach($datos_tabla as $row): 
                         $link_perfil = "editar_empleado.php?id=" . $row['id_reloj']; 
                         $link_historial = "historial.php?id=" . $row['id_reloj'];
                         
                         $ced_clean = preg_replace('/[^0-9]/', '', $row['cedula'] ?? '');
                         $foto = "fotos/" . $ced_clean . ".jpg";
                         if(!file_exists($foto)) $foto = "https://ui-avatars.com/api/?name=".urlencode($row['nombre_completo'])."&background=random&color=fff";
                         
                         $verificado_html = !empty($row['horario_verificado']) ? '<i class="fas fa-check-circle text-blue-500 ml-1" title="Verificado"></i>' : '';
                         
                         $col1_val = ''; $col2_val = ''; $col3_val = '';
                         
                         if ($vista === 'ausencias') {
                             $col1_val = '<a href="'.$link_historial.'" target="_blank" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-red-100 text-red-700 font-black border border-red-200">'.$row['faltas_calc'].'</a>';
                             $col2_val = '<form method="POST"><input type="hidden" name="id_justificar" value="'.$row['id_reloj'].'"><input type="text" name="motivo_justificacion" placeholder="Justificar..." class="border rounded px-1 text-[10px] w-20"><button name="justificar_ausencia" class="text-blue-600 font-bold ml-1">OK</button></form>';
                         } elseif ($vista === 'hoy') {
                             $horario = $row['horario_entrada'] ?: '09:00:00';
                             $llegada = $row['registro_entrada'];
                             
                             $col1_val = '<span class="text-gray-500 font-mono">'.date('g:i A', strtotime($horario)).'</span>';
                             $col2_val = $llegada ? '<a href="'.$link_historial.'" target="_blank" class="font-bold text-gray-800 font-mono hover:text-blue-600">'.date('g:i:s A', strtotime($llegada)).'</a>' : '--';
                             
                             if ($llegada) {
                                 $diff = (strtotime($llegada) - strtotime($horario)) / 60;
                                 if ($diff <= 15) $col3_val = '<a href="'.$link_historial.'" target="_blank" class="text-green-600 font-bold flex items-center justify-center gap-1 hover:underline"><div class="w-1.5 h-1.5 rounded-full bg-green-500"></div> A Tiempo</a>';
                                 elseif ($diff <= 30) $col3_val = '<a href="'.$link_historial.'" target="_blank" class="text-yellow-600 font-bold flex items-center justify-center gap-1 hover:underline"><div class="w-1.5 h-1.5 rounded-full bg-yellow-500"></div> Med. Tarde</a>';
                                 else $col3_val = '<a href="'.$link_historial.'" target="_blank" class="text-red-600 font-bold flex items-center justify-center gap-1 hover:underline"><div class="w-1.5 h-1.5 rounded-full bg-red-500"></div> Tarde</a>';
                             } else { $col3_val = '<span class="text-gray-400 italic">Pendiente</span>'; }

                         } else { // Ayer
                             $horario = $row['horario_salida'] ?: '18:00:00';
                             $salida = $row['registro_salida'];
                             $entrada = $row['registro_entrada']; 

                             $col1_val = '<span class="text-gray-500 font-mono">'.date('g:i A', strtotime($horario)).'</span>';
                             $col2_val = ($salida && $salida != '00:00:00') ? '<a href="'.$link_historial.'" target="_blank" class="font-bold text-purple-800 font-mono hover:text-blue-600">'.date('g:i:s A', strtotime($salida)).'</a>' : '--';
                             
                             if ($entrada && $salida && $salida != '00:00:00') {
                                 $seg = strtotime($salida) - strtotime($entrada);
                                 $col3_val = '<a href="'.$link_historial.'" target="_blank" class="font-bold text-gray-700 bg-green-50 px-2 py-1 rounded border border-green-100 hover:bg-green-100 transition">' . floor($seg / 3600) . 'h ' . floor(($seg % 3600) / 60) . 'm</a>';
                             } else { $col3_val = '<span class="text-gray-300">-</span>'; }
                         }
                    ?>
                    <tr class="hover:bg-blue-50 transition group">
                        <td class="px-4 py-2"><a href="<?php echo $link_perfil; ?>"><img src="<?php echo $foto; ?>" class="w-10 h-12 rounded bg-gray-200 object-cover object-top border border-gray-200 shadow-sm"></a></td>
                        <td class="px-4 py-2">
                            <a href="<?php echo $link_perfil; ?>" class="font-bold text-gray-800 group-hover:text-blue-600 hover:underline text-sm"><?php echo htmlspecialchars($row['nombre_completo']); ?></a>
                            <div class="text-[10px] text-gray-400 font-mono">ID: <?php echo $row['id_reloj']; ?></div>
                        </td>
                        <td class="px-4 py-2"><div class="text-gray-600 truncate max-w-[150px] font-medium"><?php echo htmlspecialchars($row['departamento']); ?></div><div class="text-[10px] text-gray-400 uppercase truncate max-w-[150px]"><?php echo htmlspecialchars($row['cargo']); ?></div></td>
                        <td class="px-4 py-2 text-center text-gray-500 font-mono bg-gray-50/50"><?php echo $col1_val; ?><?php if($vista!='ausencias') echo $verificado_html; ?></td>
                        <td class="px-4 py-2 text-center font-mono"><?php echo $col2_val; ?></td>
                        <td class="px-4 py-2 text-center"><?php echo $col3_val; ?></td>
                        <td class="px-4 py-2 text-right"><a href="<?php echo $link_historial; ?>" target="_blank" class="text-gray-400 hover:text-blue-600 transition"><i class="fas fa-external-link-alt"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="px-6 py-12 text-center text-gray-400 italic">No se encontraron registros.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 3. MÓDULOS DE ANÁLISIS Y ALERTAS (INFERIOR) -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">

    <!-- B) VACACIONES Y LICENCIAS (CON BUSCADOR) -->
    <div class="bg-white rounded-lg shadow border-l-4 border-purple-500 overflow-hidden h-64 flex flex-col">
        <div class="bg-purple-50 px-3 py-2 border-b border-purple-100 flex flex-col gap-2">
            <div class="flex justify-between items-center w-full">
                <h3 class="font-bold text-purple-800 text-xs flex items-center gap-1"><i class="fas fa-plane"></i> Vacaciones / Licencias</h3>
                <span class="text-[9px] bg-purple-200 text-purple-800 px-2 rounded-full font-bold"><?php echo count($ausentes_activos); ?></span>
            </div>
            <div class="relative w-full">
                <input type="text" list="lista_emps_vac" placeholder="🔍 Buscar para registrar..." class="w-full text-xs border border-purple-300 rounded px-2 py-1 outline-none focus:ring-1 focus:ring-purple-500" onchange="irAPerfilVacaciones(this)">
                <datalist id="lista_emps_vac"><?php foreach($all_employees_search as $e): ?><option value="<?php echo htmlspecialchars($e['nombre_completo']); ?>" data-id="<?php echo $e['id_reloj']; ?>">ID: <?php echo $e['id_reloj']; ?></option><?php endforeach; ?></datalist>
            </div>
        </div>
        <div class="p-0 overflow-y-auto flex-grow">
            <table class="w-full text-xs">
                <tbody class="divide-y divide-purple-50">
                <?php if(count($ausentes_activos) > 0): foreach($ausentes_activos as $vc): 
                    $dias_restantes = ceil((strtotime($vc['fecha_fin']) - strtotime($hoy)) / 86400);
                    $color_tag = ($vc['tipo'] == 'Licencia') ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700';
                ?>
                    <tr class="hover:bg-purple-50">
                        <td class="px-3 py-2 font-bold text-gray-700"><?php echo htmlspecialchars($vc['nombre_completo']); ?></td>
                        <td class="px-3 py-2 text-right">
                            <span class="<?php echo $color_tag; ?> px-1.5 rounded text-[9px] mr-1"><?php echo $vc['tipo']; ?></span>
                            <span class="font-bold text-gray-600">Retorna: <?php echo $dias_restantes; ?>d</span>
                        </td>
                    </tr>
                <?php endforeach; else: ?><tr><td colspan="2" class="p-4 text-center text-gray-400 italic">Nadie ausente hoy.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- C) NUEVOS INGRESOS -->
    <div class="bg-white rounded-lg shadow border-l-4 border-blue-500 overflow-hidden h-64 flex flex-col">
        <div class="bg-blue-50 px-3 py-2 border-b border-blue-100 flex justify-between items-center">
            <h3 class="font-bold text-blue-800 text-xs flex items-center gap-1"><i class="fas fa-user-plus"></i> Últimos Ingresos</h3>
            <a href="editar_empleado.php?id=new" class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-0.5 rounded text-[10px] font-bold shadow transition"><i class="fas fa-plus"></i> Registrar</a>
        </div>
        <div class="p-0 overflow-y-auto flex-grow">
            <table class="w-full text-xs">
                <tbody class="divide-y divide-blue-50">
                <?php foreach($nuevos_ingresos as $ni): ?>
                    <tr class="hover:bg-blue-50">
                        <td class="px-3 py-2">
                            <div class="font-bold text-gray-800"><?php echo htmlspecialchars($ni['nombre_completo']); ?></div>
                            <div class="text-[9px] text-gray-400">ID: <?php echo $ni['id_reloj']; ?></div>
                        </td>
                        <td class="px-3 py-2 text-right text-gray-500"><?php echo $ni['fecha_ingreso'] ? date('d/m/Y', strtotime($ni['fecha_ingreso'])) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- D) BAJO RENDIMIENTO (RESTAURADO) -->
    <div class="bg-white rounded-lg shadow border-l-4 border-orange-500 overflow-hidden h-64 flex flex-col">
        <div class="bg-orange-50 px-3 py-2 border-b border-orange-100 flex justify-between items-center">
            <h3 class="font-bold text-orange-800 text-xs flex items-center gap-1"><i class="fas fa-battery-quarter"></i> Jornadas Cortas (<4h)</h3>
            <span class="text-[9px] text-gray-500">Mes Actual</span>
        </div>
        <div class="p-0 overflow-y-auto flex-grow">
            <table class="w-full text-xs">
                <tbody class="divide-y divide-orange-50">
                <?php foreach($bajo_rendimiento as $b): ?>
                    <tr class="hover:bg-orange-50">
                        <td class="px-3 py-2 font-bold text-gray-700"><?php echo htmlspecialchars($b['nombre_completo']); ?></td>
                        <td class="px-3 py-2 text-right"><span class="bg-orange-100 text-orange-800 px-2 py-0.5 rounded font-bold"><?php echo $b['dias_bajos']; ?> veces</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- E) TOP ACTIVIDAD (RESTAURADO) -->
    <div class="bg-white rounded-lg shadow border-l-4 border-indigo-500 overflow-hidden h-64 flex flex-col">
        <div class="bg-indigo-50 px-3 py-2 border-b border-indigo-100 flex justify-between items-center">
            <h3 class="font-bold text-indigo-800 text-xs flex items-center gap-1"><i class="fas fa-fingerprint"></i> Mayor Actividad</h3>
            <span class="text-[9px] text-gray-500">Fichajes Totales</span>
        </div>
        <div class="p-0 overflow-y-auto flex-grow">
            <table class="w-full text-xs">
                <tbody class="divide-y divide-indigo-50">
                <?php foreach($top_actividad as $t): ?>
                    <tr class="hover:bg-indigo-50">
                        <td class="px-3 py-2 font-bold text-gray-700"><?php echo htmlspecialchars($t['nombre_completo']); ?></td>
                        <td class="px-3 py-2 text-right"><span class="text-indigo-600 font-bold"><?php echo $t['total_fichajes']; ?> fichajes</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- F) DUPLICADOS (SI EXISTEN) -->
    <?php if (count($lista_duplicados) > 0): ?>
    <div class="bg-white rounded-lg shadow border-l-4 border-gray-500 overflow-hidden h-64 flex flex-col">
        <div class="bg-gray-100 px-3 py-2 border-b border-gray-200 flex justify-between items-center">
            <h3 class="font-bold text-gray-800 text-xs flex items-center gap-1"><i class="fas fa-clone"></i> Duplicados</h3>
            <form method="POST"><button type="submit" name="fusionar_seleccionados" value="1" onclick="return confirm('¿Fusionar todo?')" class="text-[9px] bg-gray-200 text-gray-700 px-2 py-0.5 rounded font-bold hover:bg-gray-300">Fusionar Todo</button>
            <?php foreach($lista_duplicados as $dup): ?><input type="hidden" name="ids_to_merge[]" value="<?php echo htmlspecialchars($dup['ids']); ?>"><?php endforeach; ?>
            </form>
        </div>
        <div class="p-0 overflow-y-auto flex-grow">
            <table class="w-full text-xs">
                <tbody class="divide-y divide-gray-100">
                <?php foreach($lista_duplicados as $dup): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 font-bold text-gray-700"><?php echo htmlspecialchars($dup['valor']); ?></td>
                        <td class="px-3 py-2 text-right text-[9px] text-gray-500"><?php echo $dup['c']; ?> perfiles</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require 'layout_footer.php'; ?>