<?php
// historial.php - HISTORIAL: EXCLUSIÓN DE HOY + SOMBREADO
require 'auth.php'; 
verificarPermiso(['admin', 'rrhh', 'carnet']);

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
$apiKey = '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'; 

// Cargar Diccionarios
try {
    $feriados_db = $pdo->query("SELECT fecha, descripcion FROM feriados")->fetchAll(PDO::FETCH_KEY_PAIR);
    $fallos_db   = $pdo->query("SELECT fecha, motivo FROM dias_sin_sistema")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $feriados_db = []; $fallos_db = []; }

// Variables UI
$resultados_busqueda = []; $empleado = null; $asistencia = []; $termino = ''; 
$avg_entrada = '--'; $avg_salida = '--'; $avg_jornada = '--'; $puntuacion_promedio = 0;
$analisis_ia_texto = '';

// Filtros de Fecha
$filtro_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
$filtro_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d', strtotime('-30 days'));

// 1. BUSCADOR
if (isset($_GET['q'])) {
    $termino = trim($_GET['q']);
    if (strlen($termino) > 0) {
        $stmt = $pdo->prepare("SELECT * FROM empleados WHERE nombre_completo LIKE :term OR cedula LIKE :term OR id_reloj LIKE :term LIMIT 20");
        $stmt->execute([':term' => "%$termino%"]);
        $resultados_busqueda = $stmt->fetchAll();
    }
}

// 2. PROCESAMIENTO DE HISTORIAL
if (isset($_GET['id'])) {
    $id_reloj = $_GET['id'];
    $stmtEmp = $pdo->prepare("SELECT * FROM empleados WHERE id_reloj = ?");
    $stmtEmp->execute([$id_reloj]);
    $empleado = $stmtEmp->fetch();

    if ($empleado) {
        // Foto
        $cedula_limpia = preg_replace('/[^0-9]/', '', $empleado['cedula']);
        $foto_url = file_exists("fotos/$cedula_limpia.jpg") ? "fotos/$cedula_limpia.jpg?v=".time() : "https://ui-avatars.com/api/?name=".urlencode($empleado['nombre_completo'])."&background=0D8ABC&color=fff&size=200";

        // Obtener Registros
        $stmtAsist = $pdo->prepare("SELECT * FROM asistencia WHERE id_empleado_reloj = ? AND fecha BETWEEN ? AND ? ORDER BY fecha DESC");
        $stmtAsist->execute([$id_reloj, $filtro_inicio, $filtro_fin]);
        $asistencias_raw = $stmtAsist->fetchAll();

        // Mapeo
        $datos_por_fecha = []; foreach ($asistencias_raw as $r) $datos_por_fecha[$r['fecha']] = $r;
        
        $current = strtotime($filtro_fin); $stop = strtotime($filtro_inicio);
        
        // Acumuladores Promedios
        $sum_in = 0; $cnt_in = 0; 
        $sum_out = 0; $cnt_out = 0; 
        $sum_dur = 0; $cnt_dur = 0;
        $sum_score = 0; $cnt_score = 0;

        while ($current >= $stop) {
            $f_string = date('Y-m-d', $current);
            $row = isset($datos_por_fecha[$f_string]) ? $datos_por_fecha[$f_string] : ['fecha'=>$f_string,'hora_entrada'=>null,'hora_salida'=>null];
            
            $ts = strtotime($f_string); $dia_sem = date('w', $ts);
            $es_finde = ($dia_sem == 0 || $dia_sem == 6);
            $es_feriado = isset($feriados_db[$f_string]);
            $es_fallo = isset($fallos_db[$f_string]);
            $es_hoy = ($f_string === $hoy);
            
            // Estado y Puntuación
            $row['row_class'] = "hover:bg-gray-50"; $row['meta_info'] = ""; 
            $puntos = 100; $estado = "Presente"; $row['jornada_texto'] = '-';

            if ($es_hoy) {
                // SOMBREADO PARA EL DÍA EN CURSO
                $row['row_class'] = "bg-orange-50 border-l-4 border-orange-400";
            }

            if ($es_fallo) { 
                $puntos=100; $estado="Fallo Sistema"; $row['row_class']="bg-yellow-50"; $row['meta_info']="FALLO SISTEMA"; 
            } elseif ($es_feriado) { 
                $puntos=100; $estado="Feriado"; $row['row_class']="bg-blue-50"; $row['meta_info']="FERIADO"; 
            } elseif ($es_finde) { 
                $puntos=100; $estado="Fin de Semana"; $row['row_class']="bg-purple-50"; 
            } else {
                // Día Laboral
                if (empty($row['hora_entrada'])) { 
                    $puntos=0; $estado="Ausente"; $row['row_class']="bg-red-50"; 
                } elseif (empty($row['hora_salida']) || $row['hora_salida']=='00:00:00') {
                    if($es_hoy) { $puntos=100; $estado="En Curso"; } // Hoy no penaliza
                    else { $puntos=60; $estado="Sin Salida"; }
                } else {
                    $estado="Completo";
                }
                
                // --- EXCLUIR HOY DE LA PUNTUACIÓN ---
                if (!$es_hoy) {
                    $cnt_score++; $sum_score += $puntos;
                }
            }

            // --- EXCLUIR HOY DE LOS PROMEDIOS ---
            if (!$es_hoy && !$es_finde && !$es_feriado && !$es_fallo) {
                if (!empty($row['hora_entrada'])) {
                    $parts = explode(':', $row['hora_entrada']);
                    $sum_in += ($parts[0]*3600) + ($parts[1]*60) + ($parts[2]??0); $cnt_in++;
                    
                    if (!empty($row['hora_salida']) && $row['hora_salida']!='00:00:00') {
                        $parts_out = explode(':', $row['hora_salida']);
                        $sum_out += ($parts_out[0]*3600) + ($parts_out[1]*60) + ($parts_out[2]??0); $cnt_out++;
                        
                        $t_in = strtotime($row['hora_entrada']); $t_out = strtotime($row['hora_salida']);
                        if($t_out > $t_in) { $sum_dur += ($t_out - $t_in); $cnt_dur++; }
                    }
                }
            }

            // CALCULO JORNADA PARA LA TABLA (Visualización)
            if (!empty($row['hora_entrada']) && !empty($row['hora_salida']) && $row['hora_salida']!='00:00:00') {
                 $diff = strtotime($row['hora_salida']) - strtotime($row['hora_entrada']);
                 $h = floor($diff / 3600);
                 $m = floor(($diff % 3600) / 60);
                 $row['jornada_texto'] = "{$h}h {$m}m";
            }
            
            $row['estado_texto'] = $estado;
            $asistencia[] = $row;
            $current = strtotime('-1 day', $current);
        }

        if($cnt_score > 0) $puntuacion_promedio = round($sum_score/$cnt_score);
        
        // Finalizar Promedios
        if ($cnt_in > 0) $avg_entrada = date('g:i A', mktime(0, 0, $sum_in / $cnt_in));
        if ($cnt_out > 0) $avg_salida = date('g:i A', mktime(0, 0, $sum_out / $cnt_out));
        if ($cnt_dur > 0) {
            $sec = $sum_dur / $cnt_dur; $h = floor($sec / 3600); $m = floor(($sec % 3600) / 60);
            $avg_jornada = "{$h}h {$m}m";
        }

        // IA Analysis
        if (isset($_POST['analizar_ia_btn'])) {
            $prompt = "Analiza asistencia de {$empleado['nombre_completo']} ({$empleado['cargo']}) entre $filtro_inicio y $filtro_fin. Puntaje: $puntuacion_promedio/100. Promedios: Ent $avg_entrada, Sal $avg_salida. Resumido.";
            $res = callOpenAI($apiKey, $prompt);
            $analisis_ia_texto = $res['success'] ? $res['data'] : "Error IA.";
        }
    }
}

function callOpenAI($key, $prompt) {
    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["model"=>"gpt-3.5-turbo","messages"=>[["role"=>"user","content"=>$prompt]]]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer $key"]);
    $response = curl_exec($ch); curl_close($ch);
    $json = json_decode($response, true);
    return ['success'=>isset($json['choices']), 'data'=>$json['choices'][0]['message']['content']??'Error'];
}

// INCLUIR LAYOUT
require 'layout_head.php';
?>

<style>
    @media print {
        @page { margin: 1cm; size: portrait; }
        body { background: white; font-size: 10pt; }
        nav, .no-print, form, button { display: none !important; }
        .main-content { margin: 0; padding: 0; width: 100%; }
        .shadow-lg, .shadow-sm, .shadow { box-shadow: none !important; border: 1px solid #ddd; }
        .print-break { page-break-inside: avoid; }
        .bg-gray-50, .bg-slate-50, .bg-orange-50 { background: none !important; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 4px; }
        .text-3xl { font-size: 16pt; }
    }
</style>

<div class="no-print bg-white p-4 rounded-xl shadow-sm mb-6 border border-gray-200">
    <form action="" method="GET" class="flex flex-col md:flex-row gap-3 justify-between items-center">
        <div class="w-full relative">
            <input type="text" name="q" value="<?php echo htmlspecialchars($termino); ?>" placeholder="Buscar empleado..." class="w-full pl-10 pr-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-gray-50 text-sm">
            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
        </div>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-bold shadow text-sm w-full md:w-auto">Buscar</button>
    </form>
</div>

<?php if (!empty($resultados_busqueda) && !$empleado): ?>
    <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden mb-8">
        <div class="divide-y divide-gray-100">
            <?php foreach ($resultados_busqueda as $emp): ?>
                <a href="?id=<?php echo $emp['id_reloj']; ?>" class="block px-6 py-4 hover:bg-blue-50 transition flex justify-between items-center group">
                    <div>
                        <div class="font-bold text-gray-800"><?php echo htmlspecialchars($emp['nombre_completo']); ?></div>
                        <div class="text-xs text-gray-500">ID: <?php echo $emp['id_reloj']; ?></div>
                    </div>
                    <i class="fas fa-chevron-right text-gray-300 group-hover:text-blue-500"></i>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($empleado): ?>
    
    <!-- TARJETA PRINCIPAL -->
    <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden mb-6 relative print-break">
        <div class="h-2 bg-blue-600 w-full absolute top-0 no-print"></div>
        <div class="p-6 flex flex-col md:flex-row items-stretch gap-8">
            
            <!-- FOTO GRANDE -->
            <div class="flex-shrink-0 flex flex-col items-center gap-4">
                <img src="<?php echo $foto_url; ?>" class="w-40 h-40 rounded-full object-cover border-4 border-white shadow-lg bg-gray-100">
                <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 text-center w-full no-print">
                    <div class="text-3xl font-black text-blue-600"><?php echo $puntuacion_promedio; ?></div>
                    <div class="text-[10px] text-gray-400 uppercase font-bold">Puntos</div>
                    <div class="flex justify-center text-yellow-400 text-xs mt-1">
                        <?php for($i=0; $i<round($puntuacion_promedio/20); $i++) echo '<i class="fas fa-star"></i>'; ?>
                    </div>
                </div>
            </div>

            <!-- INFO DETALLADA -->
            <div class="flex-grow space-y-3">
                <div class="flex justify-between items-start">
                    <div>
                        <h1 class="text-2xl font-bold">
                            <a href="editar_empleado.php?id=<?php echo $id_reloj; ?>" class="text-gray-800 hover:text-blue-600 transition underline-offset-4 hover:underline">
                                <?php echo htmlspecialchars($empleado['nombre_completo']); ?>
                            </a>
                        </h1>
                        <div class="text-blue-600 font-bold text-sm uppercase"><?php echo htmlspecialchars($empleado['cargo']); ?></div>
                    </div>
                    <button onclick="window.print()" class="no-print bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1 rounded shadow text-xs font-bold transition flex items-center gap-2">
                        <i class="fas fa-print"></i> Imprimir Reporte
                    </button>
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 bg-gray-50 p-3 rounded-lg border border-gray-100 text-xs mt-2">
                    <div><span class="block text-gray-400 font-bold uppercase">ID Reloj</span><?php echo $empleado['id_reloj']; ?></div>
                    <div><span class="block text-gray-400 font-bold uppercase">Cédula</span><?php echo $empleado['cedula']; ?></div>
                    <div><span class="block text-gray-400 font-bold uppercase">Depto</span><?php echo $empleado['departamento']; ?></div>
                    <div><span class="block text-gray-400 font-bold uppercase">Rango Reporte</span><?php echo date('d/m', strtotime($filtro_inicio)).' - '.date('d/m', strtotime($filtro_fin)); ?></div>
                </div>

                <!-- MÉTRICAS DE PROMEDIOS (FILTRADO) -->
                <div class="grid grid-cols-3 gap-4 mt-4 print-break">
                    <div class="bg-blue-50 border border-blue-100 rounded p-2 text-center">
                        <div class="text-[10px] text-blue-800 font-bold uppercase">Media Entrada</div>
                        <div class="text-lg font-mono text-blue-900"><?php echo $avg_entrada; ?></div>
                    </div>
                    <div class="bg-purple-50 border border-purple-100 rounded p-2 text-center">
                        <div class="text-[10px] text-purple-800 font-bold uppercase">Media Salida</div>
                        <div class="text-lg font-mono text-purple-900"><?php echo $avg_salida; ?></div>
                    </div>
                    <div class="bg-green-50 border border-green-100 rounded p-2 text-center">
                        <div class="text-[10px] text-green-800 font-bold uppercase">Media Jornada</div>
                        <div class="text-lg font-mono text-green-900"><?php echo $avg_jornada; ?></div>
                    </div>
                </div>
            </div>

            <!-- ANÁLISIS IA (SOLO PANTALLA) -->
            <div class="w-full md:w-1/3 flex flex-col h-full no-print">
                <div class="bg-gradient-to-br from-indigo-50 to-white rounded-xl border border-indigo-100 shadow-sm flex-grow p-4">
                    <div class="flex justify-between items-center mb-2">
                        <h3 class="font-bold text-indigo-800 text-sm"><i class="fas fa-robot mr-1"></i> Análisis IA</h3>
                        <form method="POST"><button type="submit" name="analizar_ia_btn" class="text-[10px] bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded-full shadow transition">Generar</button></form>
                    </div>
                    <div class="text-xs text-gray-600 overflow-y-auto max-h-[120px] italic leading-relaxed">
                        <?php echo $analisis_ia_texto ?: 'Haz clic en generar para obtener un resumen inteligente...'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLA DE DETALLE -->
    <div class="bg-white rounded-xl shadow overflow-hidden border border-gray-200 print-break">
        <div class="flex flex-col md:flex-row justify-between items-center px-6 py-4 border-b border-gray-100 gap-4 bg-gray-50 no-print">
            <h3 class="font-bold text-gray-700">Detalle de Asistencia</h3>
            <form method="GET" class="flex items-center gap-2 text-sm">
                <input type="hidden" name="id" value="<?php echo $id_reloj; ?>">
                <input type="date" name="fecha_inicio" value="<?php echo $filtro_inicio; ?>" class="border rounded p-1.5 bg-white">
                <input type="date" name="fecha_fin" value="<?php echo $filtro_fin; ?>" class="border rounded p-1.5 bg-white">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded font-bold transition">Filtrar</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs font-bold text-gray-500 uppercase">
                    <tr>
                        <th class="px-6 py-3 text-left">Fecha</th>
                        <th class="px-4 py-3 text-center">Entrada</th>
                        <th class="px-4 py-3 text-center">Salida</th>
                        <th class="px-4 py-3 text-center bg-green-50 text-green-800">Jornada</th>
                        <th class="px-6 py-3 text-center">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($asistencia as $row): ?>
                        <tr class="<?php echo $row['row_class']; ?>">
                            <td class="px-6 py-3 font-bold text-gray-700">
                                <?php echo date('d/m/Y', strtotime($row['fecha'])); ?> 
                                <span class="text-[10px] text-gray-400 block font-normal"><?php echo $row['meta_info']; ?></span>
                            </td>
                            <td class="px-4 py-3 text-center text-blue-700 font-mono">
                                <?php echo $row['hora_entrada'] ? date('h:i A', strtotime($row['hora_entrada'])) : '--'; ?>
                            </td>
                            <td class="px-4 py-3 text-center text-purple-700 font-mono">
                                <!-- OCULTAR SALIDA SI ES HOY -->
                                <?php 
                                    if ($row['fecha'] == $hoy) {
                                        echo '<span class="text-gray-400 text-xs italic">En curso...</span>';
                                    } else {
                                        echo ($row['hora_salida'] && $row['hora_salida'] != '00:00:00') ? date('h:i A', strtotime($row['hora_salida'])) : '--';
                                    }
                                ?>
                            </td>
                            <td class="px-4 py-3 text-center font-bold text-gray-600 bg-green-50/30">
                                <?php echo $row['jornada_texto']; ?>
                            </td>
                            <td class="px-6 py-3 text-center">
                                <span class="text-xs font-bold px-2 py-1 rounded 
                                    <?php 
                                        if($row['estado_texto']=='Ausente' || $row['estado_texto']=='Sin Salida') echo 'bg-red-100 text-red-600';
                                        elseif($row['estado_texto']=='Feriado') echo 'bg-blue-100 text-blue-600';
                                        elseif($row['estado_texto']=='Finde') echo 'bg-purple-100 text-purple-600';
                                        elseif($row['estado_texto']=='En Curso') echo 'bg-yellow-100 text-yellow-700';
                                        else echo 'bg-green-100 text-green-600';
                                    ?>">
                                    <?php echo $row['estado_texto']; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require 'layout_footer.php'; ?>