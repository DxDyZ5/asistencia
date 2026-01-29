<?php
// cron_auto.php - SCRIPT ROBUSTO AUTOMÁTICO (MOTOR UNIFICADO)
// Se ejecuta en segundo plano. No requiere sesión.

date_default_timezone_set('America/Santo_Domingo');
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');
set_time_limit(900);

// Rutas
$base_dir = __DIR__;
$vendor_file = $base_dir . '/vendor/autoload.php';
$datanew_dir = $base_dir . '/datanew';

echo "\n========================================\n";
echo "[" . date('Y-m-d H:i:s') . "] INICIANDO ESCANEO AUTOMÁTICO\n";
echo "========================================\n";

$use_library = file_exists($vendor_file);
if ($use_library) {
    require $vendor_file;
    echo "Librería Excel cargada.\n";
} else {
    echo "AVISO: No se encuentra vendor/autoload.php. Solo se procesarán CSVs.\n";
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xls;

// --- CONEXIÓN BD ---
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
    echo "Conexión BD exitosa.\n";
} catch (PDOException $e) {
    die("ERROR CRÍTICO DB: " . $e->getMessage() . "\n");
}

// --- HELPERS (IDÉNTICOS A CARGAR_DATOS.PHP) ---
function esIdValido($val, $valid_ids) {
    if(!is_scalar($val)) return false;
    $s = trim((string)$val);
    $clean = ltrim($s, '0'); 
    return isset($valid_ids[$s]) || isset($valid_ids[$clean]) || (ctype_digit($clean) && isset($valid_ids[(int)$clean]));
}

function esFecha($val) {
    return preg_match('/^(\d{4}[-\/]\d{2}[-\/]\d{2}|\d{2}[-\/]\d{2}[-\/]\d{4})[ T]\d{2}:\d{2}:\d{2}/', trim($val));
}

function validarYAgregar(&$agrupado, $raw_id, $raw_date, $valid_ids, &$stats) {
    $id_str = trim((string)$raw_id);
    $id_clean = ltrim($id_str, '0');
    
    $final_id = null;
    if(isset($valid_ids[$id_str])) $final_id = $id_str;
    elseif(isset($valid_ids[$id_clean])) $final_id = $id_clean;
    elseif(ctype_digit($id_clean) && isset($valid_ids[(int)$id_clean])) $final_id = (int)$id_clean;

    if ($final_id === null) {
        if(!empty($id_clean)) $stats['ids_invalidos']++;
        return false;
    }

    $clean_date = str_replace(['T', '/'], [' ', '-'], $raw_date);
    $ts = false;
    if (preg_match('/^\d{2}-\d{2}-\d{4}/', $clean_date)) {
        $dto = DateTime::createFromFormat('d-m-Y H:i:s', $clean_date);
        $ts = $dto ? $dto->getTimestamp() : false;
    } else {
        $ts = strtotime($clean_date);
    }
    
    if ($ts && date('Y', $ts) > 2000) {
        $fecha = date('Y-m-d', $ts);
        $hora = date('H:i:s', $ts);
        
        if (!isset($agrupado[$final_id][$fecha])) {
            $agrupado[$final_id][$fecha] = ['min' => $hora, 'max' => $hora, 'count' => 0];
        }
        if ($hora < $agrupado[$final_id][$fecha]['min']) $agrupado[$final_id][$fecha]['min'] = $hora;
        if ($hora > $agrupado[$final_id][$fecha]['max']) $agrupado[$final_id][$fecha]['max'] = $hora;
        $agrupado[$final_id][$fecha]['count']++;
        $stats['encontrados']++;
        return true;
    }
    return false;
}

function procesarJsonRecursivo($data, $valid_ids, &$agrupado, &$stats) {
    if (is_array($data)) {
        $date_found = null; $id_found = null;
        foreach ($data as $key => $val) {
            if (is_array($val)) { procesarJsonRecursivo($val, $valid_ids, $agrupado, $stats); continue; }
            if (!$date_found && is_string($val) && esFecha($val)) $date_found = $val;
            if (!$id_found && in_array(strtolower($key), ['pin', 'user_id', 'enroll_id', 'id', 'emp_id'])) {
                if(esIdValido($val, $valid_ids)) $id_found = $val;
            }
        }
        if ($date_found && $id_found) {
            validarYAgregar($agrupado, $id_found, $date_found, $valid_ids, $stats);
            return;
        }
        if (!$date_found || !$id_found) {
            foreach ($data as $val) {
                if (!is_array($val)) {
                    if (!$date_found && esFecha($val)) $date_found = $val;
                    if (!$id_found && esIdValido($val, $valid_ids)) $id_found = $val;
                }
            }
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
            if (count($dates[0]) > 0) {
                foreach ($nums[1] as $n) {
                    if (esIdValido($n, $valid_ids)) {
                        foreach($dates[0] as $date_str) {
                            validarYAgregar($agrupado, $n, $date_str, $valid_ids, $stats);
                            $found = true;
                        }
                        break; 
                    }
                }
            }
        }
    }
    return $found;
}

// MOTOR DE PROCESAMIENTO
function procesarArchivoCron($archivo, $pdo, $valid_ids, $use_library) {
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
            if (!$use_library) return ['status' => false, 'count' => 0, 'msg' => "Requiere librería Excel."];
            if ($ext === 'xlsx') $reader = new Xlsx(); elseif ($ext === 'xls') $reader = new Xls(); else $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
            $reader->setReadDataOnly(true); 
            $spreadsheet = $reader->load($archivo);
            $hoja = $spreadsheet->getActiveSheet()->toArray();
        }

        $agrupado = [];
        $stats = ['leidos' => 0, 'encontrados' => 0, 'ids_invalidos' => 0];
        $col_tiempo = -1; $col_id = -1; $col_log = -1; $found_header = false;

        foreach ($hoja as $row) {
            if (empty($row)) continue;
            $row = array_filter($row, function($value) { return $value !== null && $value !== ''; });
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
                if (substr($json_candidate, 0, 1) === '"') {
                    $decoded = json_decode($json_candidate); if ($decoded) $json_candidate = $decoded;
                }
                if (json_decode($json_candidate) === null) {
                    if (preg_match('/(\{.*\}|\[.*\])/s', $json_candidate, $matches)) $json_candidate = $matches[1];
                }
                $json_data = json_decode($json_candidate, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    procesarJsonRecursivo($json_data, $valid_ids, $agrupado, $stats);
                    $row_processed = true;
                } elseif (strpos($content, "['") !== false) {
                    $clean = trim($content, "[]"); $parts = str_getcsv($clean, ",", "'");
                    $found_d = null; $found_i = null;
                    foreach($parts as $p) {
                        if(!$found_d && esFecha($p)) $found_d = $p;
                        if(!$found_i && esIdValido($p, $valid_ids)) $found_i = $p;
                    }
                    if($found_d && $found_i) { validarYAgregar($agrupado, $found_i, $found_d, $valid_ids, $stats); $row_processed = true; }
                } else {
                    if(parsearTextoPlano($content, $valid_ids, $agrupado, $stats)) $row_processed = true;
                }
            }

            if (!$row_processed && ($col_tiempo !== -1 && $col_id !== -1)) {
                $raw_date = $row[$col_tiempo] ?? ''; $raw_id = $row[$col_id] ?? '';
                $fecha_final = '';
                if (!empty($raw_date)) {
                    if (is_numeric($raw_date) && $ext !== 'csv' && $ext !== 'txt') $fecha_final = Date::excelToDateTimeObject($raw_date)->format('Y-m-d H:i:s');
                    else $fecha_final = $raw_date;
                }
                if(validarYAgregar($agrupado, $raw_id, $fecha_final, $valid_ids, $stats)) $row_processed = true;
            }

            if (!$row_processed) {
                $fila_entera = implode(" ", $row);
                parsearTextoPlano($fila_entera, $valid_ids, $agrupado, $stats);
            }
        }

        if (!empty($agrupado)) {
            $pdo->beginTransaction();
            // SQL DE FUSIÓN (NO REEMPLAZO)
            $stmt = $pdo->prepare("INSERT INTO asistencia (id_empleado_reloj, fecha, hora_entrada, hora_salida, total_eventos) 
                                   VALUES (?, ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE 
                                   hora_entrada = IF(hora_entrada IS NULL OR hora_entrada = '00:00:00', VALUES(hora_entrada), LEAST(hora_entrada, VALUES(hora_entrada))),
                                   hora_salida  = GREATEST(IFNULL(hora_salida, '00:00:00'), VALUES(hora_salida)),
                                   total_eventos = total_eventos + VALUES(total_eventos)");
            $total = 0;
            foreach ($agrupado as $id => $fechas) {
                foreach ($fechas as $fecha => $info) {
                    $stmt->execute([$id, $fecha, $info['min'], $info['max'], $info['count']]);
                    $total++;
                }
            }
            $pdo->commit();
            return ['status' => true, 'count' => $total, 'msg' => "OK"];
        } else {
            return ['status' => false, 'count' => 0, 'msg' => "Datos insuficientes."];
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['status' => false, 'count' => 0, 'msg' => $e->getMessage()];
    }
}

// --- EJECUCIÓN ---

if (!is_dir($datanew_dir)) mkdir($datanew_dir, 0777, true);
$archivos = glob("$datanew_dir/*.{csv,xls,xlsx,txt}", GLOB_BRACE);

if (empty($archivos)) {
    echo "Directorio vacío. Esperando archivos...\n";
} else {
    $valid_ids = $pdo->query("SELECT id_reloj FROM empleados")->fetchAll(PDO::FETCH_COLUMN);
    $valid_ids = array_flip($valid_ids);
    
    foreach ($archivos as $archivo) {
        $nombre = basename($archivo);
        echo "Procesando: $nombre ... ";
        $res = procesarArchivoCron($archivo, $pdo, $valid_ids, $use_library);
        
        if ($res['status']) {
            if (unlink($archivo)) echo "OK ({$res['count']} eventos). Archivo eliminado.\n";
            else echo "OK ({$res['count']} eventos), pero ERROR al eliminar archivo.\n";
        } else {
            echo "ERROR: {$res['msg']}\n";
        }
    }
}
echo "========================================\n";
echo "[" . date('Y-m-d H:i:s') . "] PROCESO FINALIZADO.\n";
?>