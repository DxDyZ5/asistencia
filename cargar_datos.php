<?php
// cargar_datos.php - VERSIÓN DEFINITIVA: ESCANEO PROFUNDO DE CÉDULA + FUSIÓN DE ID
require 'auth.php';
verificarPermiso(['admin', 'rrhh']);

// --- CONFIGURACIÓN ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(600);
date_default_timezone_set('America/Santo_Domingo'); 

// Verificar librería
$use_library = file_exists('vendor/autoload.php');
if ($use_library) {
    require 'vendor/autoload.php';
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xls;

// --- VARIABLES GLOBALES ---
$mensaje = '';
$tipo_mensaje = '';

// --- FUNCIONES AUXILIARES ---
function obtenerUltimoEvento($pdo) {
    $sql = "SELECT fecha, hora_salida FROM asistencia ORDER BY fecha DESC, hora_salida DESC LIMIT 1";
    $stmt = $pdo->query($sql);
    $row = $stmt->fetch();
    if ($row) {
        return date('d-m-Y h:i:s A', strtotime($row['fecha'] . ' ' . $row['hora_salida']));
    }
    return 'Sin registros';
}

function parsearFechaSegura($val) {
    if (empty($val)) return null;
    $val = trim($val);
    if (is_numeric($val)) {
        if ($val > 18000 && $val < 55000) { 
            try { return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($val)->format('Y-m-d'); } catch (Exception $e) { return null; }
        } else { return null; }
    }
    $formatos = ['d-M-y', 'd-M-Y', 'd/m/Y', 'Y-m-d', 'd-m-Y'];
    foreach ($formatos as $fmt) {
        $d = DateTime::createFromFormat($fmt, $val);
        if ($d && $d->format($fmt) == $val) {
            $year = $d->format('Y');
            if ($year > 1950 && $year < 2050) return $d->format('Y-m-d');
        }
    }
    $ts = strtotime($val);
    if ($ts) {
        $year = date('Y', $ts);
        if ($year > 1950 && $year < 2050) return date('Y-m-d', $ts);
    }
    return null;
}

$ultimo_evento_db = obtenerUltimoEvento($pdo);
$fecha_actual = date('d-m-Y h:i:s A'); 

// --- MOTOR DE PROCESAMIENTO DE EVENTOS ---
function procesarArchivoEventos($archivo, $pdo, $valid_ids, $use_library) {
    try {
        $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        $hoja = []; 

        if ($ext === 'csv' || $ext === 'txt') {
            $delimiter = ",";
            if (($handle = fopen($archivo, "r")) !== FALSE) {
                $line = fgets($handle);
                if (substr_count($line, "\t") > substr_count($line, ",")) $delimiter = "\t";
                elseif (substr_count($line, ";") > substr_count($line, ",")) $delimiter = ";";
                rewind($handle);
                while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                    $clean_data = array_map(function($val) {
                        return trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $val ?? ''));
                    }, $data);
                    $hoja[] = $clean_data;
                }
                fclose($handle);
            }
        } else {
            if (!$use_library) throw new Exception("Librería Excel requerida para .$ext");
            if ($ext === 'xlsx') $reader = new Xlsx(); elseif ($ext === 'xls') $reader = new Xls(); else $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
            $reader->setReadDataOnly(true); 
            $spreadsheet = $reader->load($archivo);
            $hoja = $spreadsheet->getActiveSheet()->toArray();
        }

        $agrupado = [];
        $stats = ['leidos' => 0, 'encontrados' => 0, 'ids_invalidos' => 0];
        $col_tiempo = -1; $col_id = -1; $col_log = -1; $found_header = false;
        
        foreach ($hoja as $row_idx => $row) {
            if (empty($row)) continue;
            if (!$found_header) {
                foreach ($row as $key => $cell) {
                    $cell = trim(strtolower((string)$cell)); 
                    if (in_array($cell, ['msg', 'raw_text', 'response', 'body', 'ret', 'data', 'content', 'message'])) $col_log = $key;
                    if (in_array($cell, ['tiempo', 'time', 'fecha', 'date', 'fecha/hora', 'datetime', 'checktime', 'hora'])) $col_tiempo = $key;
                    if (in_array($cell, ['id', 'no.', 'ac-no.', 'enrol. no', 'user id', 'pin', 'usuario', 'empleado'])) $col_id = $key;
                }
                if ($col_log !== -1 || ($col_tiempo !== -1 && $col_id !== -1)) { $found_header = true; continue; }
            }
            $stats['leidos']++;
            $row_processed = false;

            if ($col_log !== -1 && isset($row[$col_log])) {
                $content = $row[$col_log];
                $json_candidate = trim($content);
                if (substr($json_candidate, 0, 1) === '"') { $decoded = json_decode($json_candidate); if ($decoded) $json_candidate = $decoded; }
                if (json_decode($json_candidate) === null) { if (preg_match('/(\{.*\}|\[.*\])/s', $json_candidate, $matches)) $json_candidate = $matches[1]; }
                $json_data = json_decode($json_candidate, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    procesarJsonRecursivo($json_data, $valid_ids, $agrupado, $stats); $row_processed = true;
                } elseif (strpos($content, "['") !== false) {
                    $clean = trim($content, "[]"); $parts = str_getcsv($clean, ",", "'");
                    $found_d = null; $found_i = null;
                    foreach($parts as $p) { if(!$found_d && esFecha($p)) $found_d = $p; if(!$found_i && esIdValido($p, $valid_ids)) $found_i = $p; }
                    if($found_d && $found_i) { validarYAgregar($agrupado, $found_i, $found_d, $valid_ids, $stats); $row_processed = true; }
                } else {
                    if(parsearTextoPlano($content, $valid_ids, $agrupado, $stats)) $row_processed = true;
                }
            }
            if (!$row_processed && ($col_tiempo !== -1 && $col_id !== -1)) {
                $raw_date = $row[$col_tiempo] ?? ''; $raw_id = $row[$col_id] ?? ''; $fecha_final = '';
                if (!empty($raw_date)) {
                    if (is_numeric($raw_date) && $ext !== 'csv' && $ext !== 'txt') { $fecha_final = Date::excelToDateTimeObject($raw_date)->format('Y-m-d H:i:s'); } else { $fecha_final = $raw_date; }
                }
                if(validarYAgregar($agrupado, $raw_id, $fecha_final, $valid_ids, $stats)) $row_processed = true;
            }
            if (!$row_processed) { $fila_entera = implode(" ", $row); parsearTextoPlano($fila_entera, $valid_ids, $agrupado, $stats); }
        }

        if (!empty($agrupado)) {
            $pdo->beginTransaction();
            $sqlInsert = "INSERT INTO asistencia (id_empleado_reloj, fecha, hora_entrada, hora_salida, total_eventos) 
                          VALUES (?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE 
                          hora_entrada = IF(hora_entrada IS NULL OR hora_entrada = '00:00:00', VALUES(hora_entrada), LEAST(hora_entrada, VALUES(hora_entrada))),
                          hora_salida  = GREATEST(IFNULL(hora_salida, '00:00:00'), VALUES(hora_salida)),
                          total_eventos = total_eventos + VALUES(total_eventos)";
            $stmt = $pdo->prepare($sqlInsert);
            $total = 0;
            foreach ($agrupado as $id => $fechas) { foreach ($fechas as $fecha => $info) { $stmt->execute([$id, $fecha, $info['min'], $info['max'], $info['count']]); $total++; } }
            $pdo->commit();
            return ['status' => true, 'count' => $total, 'msg' => ""];
        } else { return ['status' => false, 'count' => 0, 'msg' => "No se encontraron datos válidos. Leídos: {$stats['leidos']}."]; }
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return ['status' => false, 'count' => 0, 'msg' => $e->getMessage()]; }
}

// --- HELPERS (Eventos) ---
function esIdValido($val, $valid_ids) {
    if(!is_scalar($val)) return false; $s = trim((string)$val); $clean = ltrim($s, '0'); 
    return isset($valid_ids[$s]) || isset($valid_ids[$clean]) || (ctype_digit($clean) && isset($valid_ids[(int)$clean]));
}
function esFecha($val) { return preg_match('/^(\d{4}[-\/]\d{2}[-\/]\d{2}|\d{2}[-\/]\d{2}[-\/]\d{4})[ T]\d{2}:\d{2}:\d{2}/', trim($val)); }
function validarYAgregar(&$agrupado, $raw_id, $raw_date, $valid_ids, &$stats) {
    $id_str = trim((string)$raw_id); $id_clean = ltrim($id_str, '0'); $final_id = null;
    if(isset($valid_ids[$id_str])) $final_id = $id_str; elseif(isset($valid_ids[$id_clean])) $final_id = $id_clean; elseif(ctype_digit($id_clean) && isset($valid_ids[(int)$id_clean])) $final_id = (int)$id_clean;
    if ($final_id === null) { if(!empty($id_clean)) $stats['ids_invalidos']++; return false; }
    $clean_date = str_replace(['T', '/'], [' ', '-'], $raw_date); $ts = false;
    if (preg_match('/^\d{2}-\d{2}-\d{4}/', $clean_date)) { $dto = DateTime::createFromFormat('d-m-Y H:i:s', $clean_date); $ts = $dto ? $dto->getTimestamp() : false; } else { $ts = strtotime($clean_date); }
    if ($ts && date('Y', $ts) > 2000) {
        $fecha = date('Y-m-d', $ts); $hora = date('H:i:s', $ts);
        if (!isset($agrupado[$final_id][$fecha])) $agrupado[$final_id][$fecha] = ['min' => $hora, 'max' => $hora, 'count' => 0];
        if ($hora < $agrupado[$final_id][$fecha]['min']) $agrupado[$final_id][$fecha]['min'] = $hora;
        if ($hora > $agrupado[$final_id][$fecha]['max']) $agrupado[$final_id][$fecha]['max'] = $hora;
        $agrupado[$final_id][$fecha]['count']++; $stats['encontrados']++; return true;
    }
    return false;
}
function procesarJsonRecursivo($data, $valid_ids, &$agrupado, &$stats) {
    if (is_array($data)) {
        $date_found = null; $id_found = null;
        foreach ($data as $key => $val) {
            if (is_array($val)) { procesarJsonRecursivo($val, $valid_ids, $agrupado, $stats); continue; }
            if (!$date_found && is_string($val) && esFecha($val)) $date_found = $val;
            if (!$id_found && in_array(strtolower($key), ['pin', 'user_id', 'enroll_id', 'id', 'emp_id'])) if(esIdValido($val, $valid_ids)) $id_found = $val;
        }
        if ($date_found && $id_found) { validarYAgregar($agrupado, $id_found, $date_found, $valid_ids, $stats); return; }
        if (!$date_found || !$id_found) {
            foreach ($data as $val) { if (!is_array($val)) { if (!$date_found && esFecha($val)) $date_found = $val; if (!$id_found && esIdValido($val, $valid_ids)) $id_found = $val; } }
            if ($date_found && $id_found) validarYAgregar($agrupado, $id_found, $date_found, $valid_ids, $stats);
        }
    }
}
function parsearTextoPlano($content, $valid_ids, &$agrupado, &$stats) {
    $found = false;
    if (preg_match_all('/(\d{4}[-\/]\d{2}[-\/]\d{2}|\d{2}[-\/]\d{2}[-\/]\d{4})[ T]\d{2}:\d{2}:\d{2}/', $content, $dates)) {
        $clean_content = $content;
        foreach ($dates[0] as $d) $clean_content = str_replace($d, ' ', $clean_content);
        if (preg_match_all('/\b(\d+)\b/', $clean_content, $nums)) {
            if (count($dates[0]) > 0) { foreach ($nums[1] as $n) { if (esIdValido($n, $valid_ids)) { foreach($dates[0] as $date_str) { validarYAgregar($agrupado, $n, $date_str, $valid_ids, $stats); $found = true; } break; } } }
        }
    }
    return $found;
}

// --- POST HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CASO 1: CARGA MANUAL (EVENTOS)
    if (isset($_FILES['file_eventos']) && $_FILES['file_eventos']['error'] === 0) {
        $valid_ids = $pdo->query("SELECT id_reloj FROM empleados")->fetchAll(PDO::FETCH_COLUMN);
        $valid_ids = array_flip($valid_ids);
        $res = procesarArchivoEventos($_FILES['file_eventos']['tmp_name'], $pdo, $valid_ids, $use_library);
        if ($res['status']) { $mensaje = "✅ Archivo Manual: <b>{$res['count']}</b> registros procesados."; $tipo_mensaje = "success"; } 
        else { $mensaje = "⚠ Error Manual: " . $res['msg']; $tipo_mensaje = "error"; }
    }

    // CASO 2: ESCANEO AUTOMÁTICO
    if (isset($_POST['accion']) && $_POST['accion'] === 'escanear_datanew') {
        $dir = __DIR__ . '/datanew';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $archivos = glob("$dir/*.{csv,xls,xlsx}", GLOB_BRACE);
        if (empty($archivos)) { $mensaje = "ℹ El directorio <b>/datanew</b> está vacío."; $tipo_mensaje = "yellow"; } 
        else {
            $valid_ids = $pdo->query("SELECT id_reloj FROM empleados")->fetchAll(PDO::FETCH_COLUMN); $valid_ids = array_flip($valid_ids); 
            $total_general = 0; $archivos_procesados = 0; $errores = [];
            foreach ($archivos as $archivo) {
                $nombre_base = basename($archivo); $res = procesarArchivoEventos($archivo, $pdo, $valid_ids, $use_library);
                if ($res['status']) { if (unlink($archivo)) { $total_general += $res['count']; $archivos_procesados++; } else { $errores[] = "$nombre_base (Error borrado)"; } } 
                else { $errores[] = "$nombre_base ({$res['msg']})"; }
            }
            if ($archivos_procesados > 0) { $mensaje = "✅ <b>$archivos_procesados</b> archivos procesados. Total eventos: <b>$total_general</b>."; $tipo_mensaje = "success"; }
            if (!empty($errores)) { $mensaje .= "<br>⚠ Errores:<br>" . implode("<br>", $errores); if ($tipo_mensaje == "") $tipo_mensaje = "error"; }
        }
        $ultimo_evento_db = obtenerUltimoEvento($pdo);
    }

    // =========================================================================================
    // CASO 3: CARGA USUARIOS (RELOJ) - SCAN DE CÉDULA + MIGRACIÓN DE ID
    // =========================================================================================
    if (isset($_FILES['file_usuarios'])) {
        try {
            $ext = strtolower(pathinfo($_FILES['file_usuarios']['name'], PATHINFO_EXTENSION));
            $archivo = $_FILES['file_usuarios']['tmp_name'];
            if ($ext === 'csv') { $delim = ","; if(($h = fopen($archivo, "r")) !== FALSE) { $l = fgets($h); if(substr_count($l, "\t") > substr_count($l, ",")) $delim = "\t"; fclose($h); } $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv(); $reader->setDelimiter($delim); } 
            elseif ($ext === 'xls') { $reader = new Xls(); } else { $reader = new Xlsx(); }
            
            $spreadsheet = $reader->load($archivo);
            $hoja = $spreadsheet->getActiveSheet()->toArray();
            
            $pdo->beginTransaction();
            
            // Consultas Preparadas
            $stmtCheckCed = $pdo->prepare("SELECT id_reloj FROM empleados WHERE cedula = ? LIMIT 1");
            $stmtCheckID = $pdo->prepare("SELECT id_reloj FROM empleados WHERE id_reloj = ? LIMIT 1");
            
            // Query de inserción (On Duplicate actualiza nombre y cédula)
            $stmtInsert = $pdo->prepare("INSERT INTO empleados (id_reloj, nombre_completo, cedula, departamento, tarjeta) VALUES (?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                nombre_completo = IF(nombre_completo IS NULL OR nombre_completo = '', VALUES(nombre_completo), nombre_completo),
                cedula = IF(cedula IS NULL OR cedula = '', VALUES(cedula), cedula)");
            
            $count_insertados = 0;
            $count_migrados = 0;

            foreach ($hoja as $i => $r) {
                if ($i < 1) continue; 
                
                // 1. Obtener ID y Nombre
                $id_excel = filter_var($r[0]??'', FILTER_SANITIZE_NUMBER_INT);
                $nom_excel = trim(($r[1]??'').' '.($r[2]??''));
                
                // 2. BUSCAR CÉDULA EN CUALQUIER COLUMNA (Estrategia agresiva)
                $ced_excel = '';
                foreach ($r as $key => $cell) {
                    if ($key < 2) continue; // Saltar columnas de ID y Nombre
                    $limpio = preg_replace('/[^0-9]/', '', $cell);
                    // Si parece cédula dominicana (11 dígitos), la tomamos
                    if (strlen($limpio) == 11) { $ced_excel = $limpio; break; }
                }
                
                if ($id_excel) {
                    $migrado = false;

                    // 3. SI ENCONTRAMOS CÉDULA, VERIFICAMOS SI YA EXISTE ESE USUARIO
                    if (!empty($ced_excel)) {
                        $stmtCheckCed->execute([$ced_excel]);
                        $id_actual_db = $stmtCheckCed->fetchColumn();

                        if ($id_actual_db && $id_actual_db != $id_excel) {
                            // CÉDULA EXISTENTE PERO ID DISTINTO.
                            // REGLA: Mover el usuario al ID del Excel.
                            
                            // A. Limpiar el ID destino si está ocupado
                            $stmtCheckID->execute([$id_excel]);
                            if ($stmtCheckID->fetchColumn()) {
                                $pdo->prepare("DELETE FROM asistencia WHERE id_empleado_reloj = ?")->execute([$id_excel]);
                                $pdo->prepare("DELETE FROM documentos_empleado WHERE id_empleado_reloj = ?")->execute([$id_excel]);
                                $pdo->prepare("DELETE FROM excepciones_asistencia WHERE id_empleado_reloj = ?")->execute([$id_excel]);
                                $pdo->prepare("DELETE FROM empleados WHERE id_reloj = ?")->execute([$id_excel]);
                            }

                            // B. Mover datos al nuevo ID
                            $pdo->prepare("UPDATE IGNORE asistencia SET id_empleado_reloj = ? WHERE id_empleado_reloj = ?")->execute([$id_excel, $id_actual_db]);
                            $pdo->prepare("UPDATE IGNORE documentos_empleado SET id_empleado_reloj = ? WHERE id_empleado_reloj = ?")->execute([$id_excel, $id_actual_db]);
                            $pdo->prepare("UPDATE IGNORE excepciones_asistencia SET id_empleado_reloj = ? WHERE id_empleado_reloj = ?")->execute([$id_excel, $id_actual_db]);
                            
                            // C. Mover perfil maestro
                            $pdo->prepare("UPDATE IGNORE empleados SET id_reloj = ? WHERE id_reloj = ?")->execute([$id_excel, $id_actual_db]);
                            
                            $migrado = true;
                            $count_migrados++;
                        }
                    }

                    // 4. SI NO FUE MIGRADO, INSERTAR O ACTUALIZAR
                    if (!$migrado) {
                        $stmtInsert->execute([$id_excel, $nom_excel, $ced_excel, $r[4]??'', $r[7]??'']);
                        $count_insertados++;
                    }
                }
            }
            $pdo->commit();
            $mensaje = "✅ Usuarios procesados (Reloj).<br>Actualizados/Nuevos: <b>$count_insertados</b><br>Fusionados (Cédula): <b>$count_migrados</b>"; 
            $tipo_mensaje="success";
        } catch(Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $mensaje=$e->getMessage(); $tipo_mensaje="error"; }
    }
    
    // CASO 4: CARGA NÓMINA (SIN CAMBIOS)
    if (isset($_FILES['file_nomina'])) {
        try {
            $ext = strtolower(pathinfo($_FILES['file_nomina']['name'], PATHINFO_EXTENSION));
            $archivo = $_FILES['file_nomina']['tmp_name'];
            if ($ext === 'csv') $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
            elseif ($ext === 'xls') $reader = new Xls();
            else $reader = new Xlsx();

            $spreadsheet = $reader->load($archivo);
            $hoja = $spreadsheet->getActiveSheet()->toArray();
            
            $pdo->beginTransaction();
            
            $stmtCheckCed = $pdo->prepare("SELECT id_reloj FROM empleados WHERE cedula = ? AND cedula != '' LIMIT 1");
            $stmtCheckID = $pdo->prepare("SELECT id_reloj FROM empleados WHERE id_reloj = ? LIMIT 1");
            $sqlUpd = "UPDATE empleados SET nombre_completo=?, departamento=?, cargo=?, notas_nomina=?, tipo_personal=?, fecha_ingreso = NULLIF(LEAST(COALESCE(fecha_ingreso, '9999-12-31'), COALESCE(?, '9999-12-31')), '9999-12-31'), estatus_nomina=? WHERE id_reloj=?";
            $stmtUpdate = $pdo->prepare($sqlUpd);
            $stmtInsert = $pdo->prepare("INSERT INTO empleados (id_reloj, nombre_completo, cedula, departamento, cargo, notas_nomina, tipo_personal, fecha_ingreso, estatus_nomina) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $cont_updates = 0; $cont_inserts = 0;

            foreach ($hoja as $i => $row) {
                if ($i == 0) continue; 
                $id_excel = filter_var($row[0] ?? '', FILTER_SANITIZE_NUMBER_INT);
                $nom = trim(($row[1]??'') . ' ' . ($row[2]??'')); 
                $ced = preg_replace('/[^0-9]/', '', $row[3]??'');
                $ing = parsearFechaSegura($row[4] ?? '');
                $dep = $row[5]??''; $carg = $row[6]??''; $nota = strtoupper(trim($row[7]??''));
                $estatus_raw = isset($row[8]) ? trim($row[8]) : ''; $estatus_final = !empty($estatus_raw) ? $estatus_raw : 'En Nomina';
                $tipo = (strpos($nota, 'FUERA') !== false) ? 'externo' : 'planta';

                $id_target = null;

                if (!empty($ced)) {
                    $stmtCheckCed->execute([$ced]);
                    $id_target = $stmtCheckCed->fetchColumn();
                }

                if (!$id_target && !empty($id_excel)) {
                    $stmtCheckID->execute([$id_excel]);
                    if($stmtCheckID->fetchColumn()) $id_target = $id_excel;
                }

                if ($id_target) {
                    $stmtUpdate->execute([$nom, $dep, $carg, $nota, $tipo, $ing, $estatus_final, $id_target]);
                    $cont_updates++;
                } else {
                    if (!empty($id_excel)) { try { $stmtInsert->execute([$id_excel, $nom, $ced, $dep, $carg, $nota, $tipo, $ing, $estatus_final]); $cont_inserts++; } catch(Exception $e){} }
                }
            }
            $pdo->commit();
            $mensaje = "✅ Nómina procesada.<br>Actualizados: <b>$cont_updates</b><br>Nuevos: <b>$cont_inserts</b>"; 
            $tipo_mensaje="success";
        } catch(Exception $e) { if($pdo->inTransaction()) $pdo->rollBack(); $mensaje=$e->getMessage(); $tipo_mensaje="error"; }
    }
}

require 'layout_head.php';
?>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
    <div class="bg-white p-4 rounded-lg shadow border-l-4 border-blue-500 flex justify-between items-center">
        <div><p class="text-xs text-gray-500 uppercase font-bold">Fecha Actual</p><p class="text-xl font-bold text-gray-800 tracking-wide font-mono"><?php echo $fecha_actual; ?></p></div>
        <i class="fas fa-calendar-alt text-3xl text-blue-200"></i>
    </div>
    <div class="bg-white p-4 rounded-lg shadow border-l-4 border-green-500 flex justify-between items-center">
        <div><p class="text-xs text-gray-500 uppercase font-bold">Último Evento</p><p class="text-xl font-bold text-green-700 tracking-wide font-mono"><?php echo $ultimo_evento_db; ?></p></div>
        <i class="fas fa-history text-3xl text-green-200"></i>
    </div>
</div>

<?php if($mensaje): ?>
    <div class="mb-6 p-4 rounded-lg border bg-white shadow-sm <?php echo $tipo_mensaje=='success'?'border-green-400 text-green-700 bg-green-50':($tipo_mensaje=='yellow'?'border-yellow-400 text-yellow-800 bg-yellow-50':'border-red-400 text-red-700 bg-red-50'); ?>">
        <?php echo $mensaje; ?>
    </div>
<?php endif; ?>

<?php if (!$use_library): ?>
    <div class="mb-6 p-4 rounded-lg border bg-yellow-50 border-yellow-400 text-yellow-800 shadow-sm flex items-center gap-3">
        <i class="fas fa-exclamation-triangle text-2xl"></i><div><span class="font-bold">Librería de Excel no detectada.</span><p class="text-sm mt-1">Solo CSV disponible.</p></div>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <div class="lg:col-span-1 space-y-6">
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 text-white p-6 rounded-xl shadow-lg relative overflow-hidden">
            <div class="absolute top-0 right-0 -mt-4 -mr-4 w-24 h-24 bg-white opacity-10 rounded-full blur-xl"></div>
            <h2 class="text-xl font-bold mb-4 flex items-center"><i class="fas fa-robot mr-2"></i> Escaneo Automático</h2>
            <p class="text-slate-300 text-sm mb-6">Busca archivos <b>.csv, .xls, .xlsx</b> en <code>/datanew</code>.</p>
            <form method="POST"><input type="hidden" name="accion" value="escanear_datanew"><button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-3 px-4 rounded-lg shadow-lg transform hover:scale-105 transition flex items-center justify-center gap-2"><i class="fas fa-sync-alt fa-spin-hover"></i> Procesar /datanew</button></form>
            <div class="mt-4 pt-4 border-t border-slate-700 text-xs text-slate-400 text-center"><i class="fas fa-folder-open mr-1"></i> Ruta: <?php echo __DIR__ . '/datanew'; ?></div>
        </div>
        <div class="bg-white p-6 rounded-xl shadow border border-gray-200">
            <h3 class="font-bold text-gray-700 mb-3"><i class="fas fa-info-circle text-blue-500"></i> Notas Importantes</h3>
            <ul class="text-sm text-gray-600 space-y-2 list-disc pl-4"><li>El escaneo borra los archivos.</li><li>Soporta Logs API y Excel.</li></ul>
        </div>
    </div>

    <div class="lg:col-span-2 bg-white p-8 rounded-xl shadow-lg border border-gray-200">
        <h2 class="text-2xl font-bold text-gray-700 mb-6 border-b pb-2">Carga Manual de Archivos</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="p-5 bg-orange-50 rounded-lg border border-orange-200">
                <div class="flex items-center gap-2 mb-3 text-orange-800 font-bold"><span class="bg-orange-600 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs">1</span>Usuarios (Reloj)</div>
                <p class="text-xs text-gray-500 mb-2">Escanea Cédula en todas las columnas para fusionar.</p>
                <form method="POST" enctype="multipart/form-data" class="flex flex-col gap-3">
                    <input type="file" name="file_usuarios" accept=".xlsx,.xls,.csv" required class="text-sm file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-orange-100 file:text-orange-700 hover:file:bg-orange-200">
                    <button class="bg-orange-600 hover:bg-orange-700 text-white py-1 px-3 rounded text-sm transition">Subir</button>
                </form>
            </div>
            <div class="p-5 bg-purple-50 rounded-lg border border-purple-200">
                <div class="flex items-center gap-2 mb-3 text-purple-800 font-bold"><span class="bg-purple-600 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs">2</span>Nómina (RRHH)</div>
                <p class="text-xs text-gray-500 mb-2">Unifica por Cédula. Actualiza Estatus.</p>
                <form method="POST" enctype="multipart/form-data" class="flex flex-col gap-3">
                    <input type="file" name="file_nomina" accept=".xlsx,.xls,.csv" required class="text-sm file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-purple-100 file:text-purple-700 hover:file:bg-purple-200">
                    <button class="bg-purple-600 hover:bg-purple-700 text-white py-1 px-3 rounded text-sm transition">Subir y Unificar</button>
                </form>
            </div>
            <div class="md:col-span-2 p-6 bg-blue-50 rounded-lg border border-blue-200">
                <div class="flex items-center gap-2 mb-3 text-blue-800 font-bold text-lg"><span class="bg-blue-600 text-white w-8 h-8 rounded-full flex items-center justify-center text-sm">3</span>Eventos (Manual)</div>
                <form method="POST" enctype="multipart/form-data" class="flex flex-col md:flex-row gap-4 items-center">
                    <input type="file" name="file_eventos" accept=".xlsx,.xls,.csv" required class="block w-full text-sm border border-blue-300 rounded p-2 bg-white">
                    <button type="submit" class="w-full md:w-auto bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-8 rounded shadow flex items-center justify-center gap-2"><i class="fas fa-upload"></i> Cargar Eventos</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require 'layout_footer.php'; ?>