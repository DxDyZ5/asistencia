<?php
// exportar_datos.php - MÓDULO DE EXPORTACIÓN Y REPORTES (ACTUALIZADO)
require 'auth.php';
verificarPermiso(['admin', 'rrhh']);

// Verificar si existe la librería
if (!file_exists('vendor/autoload.php')) {
    die("Error: Se requiere la librería PhpSpreadsheet (vendor/autoload.php) para generar Excels.");
}

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

// --- DICCIONARIO DE COLUMNAS DISPONIBLES ---
// Se agregó 'estatus_nomina' para que puedas seleccionarlo manualmente
$columnas_map = [
    'id_reloj'        => 'ID Reloj',
    'nombre_completo' => 'Nombre Completo',
    'cedula'          => 'Cédula',
    'departamento'    => 'Departamento',
    'cargo'           => 'Cargo',
    'fecha_ingreso'   => 'Fecha Ingreso',
    'tipo_personal'   => 'Tipo Personal',
    'notas_nomina'    => 'Notas / Estado',
    'estatus_nomina'  => 'Estatus Nómina', // <--- NUEVA COLUMNA
    'tarjeta'         => 'Num. Tarjeta',
    'solicitud_carnet'=> 'Estado Carnet'
];

// --- PROCESAR SOLICITUD DE EXPORTACIÓN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exportar'])) {
    
    // Limpiar buffer para evitar archivos corruptos
    if (ob_get_length()) ob_end_clean();

    $formato_especial = $_POST['preset_mode'] ?? 'custom'; 
    
    try {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $sql = "";
        $headers = [];
        $campos_bd = [];
        
        if ($formato_especial === 'nomina_reimportable') {
            // ESTRUCTURA ESTRICTA PARA CARGAR_DATOS.PHP (ACTUALIZADA)
            // Incluye la columna 'ESTATUS NOMINA' al final para la actualización masiva
            $headers = [
                'ID RELOJ', 
                'NOMBRE', 
                'SEGUNDO_NOMBRE (Opcional)', 
                'CEDULA', 
                'FECHA INGRESO', 
                'DEPARTAMENTO', 
                'CARGO', 
                'NOTAS NOMINA',
                'ESTATUS NOMINA' // <--- NUEVA COLUMNA EN PLANTILLA
            ];
            
            // Consulta SQL ajustada
            $sql = "SELECT id_reloj, nombre_completo, '' as vacio, cedula, fecha_ingreso, departamento, cargo, notas_nomina, estatus_nomina FROM empleados ORDER BY id_reloj ASC";
            
            $nombre_archivo = "Plantilla_Correccion_Nomina_" . date('Y-m-d');
        } else {
            // MODO PERSONALIZADO
            $seleccion = $_POST['columnas'] ?? [];
            if (empty($seleccion)) throw new Exception("Debes seleccionar al menos una columna.");
            
            foreach ($seleccion as $campo) {
                if (array_key_exists($campo, $columnas_map)) {
                    $campos_bd[] = $campo;
                    $headers[] = $columnas_map[$campo];
                }
            }
            
            $sql = "SELECT " . implode(", ", $campos_bd) . " FROM empleados ORDER BY departamento, nombre_completo";
            $nombre_archivo = "Reporte_Personalizado_" . date('Y-m-d');
        }

        // Ejecutar consulta
        $stmt = $pdo->query($sql);
        $fila = 2; // Fila inicial de datos

        // ESTILOS DE ENCABEZADO
        $styleHeader = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']], 
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ];

        // Escribir Encabezados
        $colLetra = 'A';
        foreach ($headers as $idx => $header) {
            $colLetra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $sheet->setCellValue($colLetra . '1', $header);
            $sheet->getColumnDimension($colLetra)->setAutoSize(true);
        }
        $sheet->getStyle('A1:' . $colLetra . '1')->applyFromArray($styleHeader);

        // Escribir Datos
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            foreach ($row as $idx => $valor) {
                $colLetra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
                $sheet->setCellValueExplicit($colLetra . $fila, $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $fila++;
        }

        // Descarga
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $nombre_archivo . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Obtener total de empleados
$total_emp = $pdo->query("SELECT COUNT(*) FROM empleados")->fetchColumn();

require 'layout_head.php';
?>

<div class="max-w-5xl mx-auto">
    
    <div class="mb-8 text-center md:text-left">
        <h1 class="text-3xl font-bold text-gray-800"><i class="fas fa-file-export text-green-600 mr-2"></i> Exportar Datos</h1>
        <p class="text-gray-500 mt-1">Genera listados de empleados en Excel para reportes o para realizar correcciones masivas.</p>
    </div>

    <?php if(isset($error)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p class="font-bold">Error al exportar</p>
            <p><?php echo $error; ?></p>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <div class="lg:col-span-2 bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            <div class="bg-gray-50 p-4 border-b border-gray-200 flex justify-between items-center">
                <div>
                    <h2 class="font-bold text-gray-700"><i class="fas fa-sliders-h"></i> Reporte Personalizado</h2>
                    <p class="text-xs text-gray-500">Selecciona los datos que deseas visualizar.</p>
                </div>
                <span class="bg-blue-100 text-blue-800 text-xs font-bold px-2 py-1 rounded-full"><?php echo $total_emp; ?> registros</span>
            </div>
            
            <form method="POST" class="p-6">
                <input type="hidden" name="exportar" value="1">
                <input type="hidden" name="preset_mode" value="custom">
                
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
                    <?php foreach ($columnas_map as $col_db => $label): ?>
                    <label class="flex items-center space-x-3 p-3 border rounded-lg hover:bg-blue-50 cursor-pointer transition select-none">
                        <input type="checkbox" name="columnas[]" value="<?php echo $col_db; ?>" class="form-checkbox h-5 w-5 text-blue-600 rounded focus:ring-blue-500 border-gray-300" 
                        <?php echo in_array($col_db, ['id_reloj', 'nombre_completo', 'estatus_nomina']) ? 'checked' : ''; ?>>
                        <span class="text-sm font-medium text-gray-700"><?php echo $label; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <button type="button" onclick="selectAll()" class="text-sm text-blue-600 hover:text-blue-800 font-bold">
                        <i class="fas fa-check-double"></i> Marcar Todos
                    </button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg shadow-md transition transform hover:scale-105 flex items-center gap-2">
                        <i class="fas fa-file-excel"></i> Descargar Reporte
                    </button>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            
            <div class="bg-white rounded-xl shadow-md border border-purple-200 overflow-hidden relative">
                <div class="absolute top-0 right-0 w-16 h-16 bg-purple-100 rounded-bl-full -mr-8 -mt-8"></div>
                
                <div class="p-5">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="bg-purple-100 p-3 rounded-full text-purple-600">
                            <i class="fas fa-sync-alt text-xl"></i>
                        </div>
                        <h3 class="font-bold text-gray-800">Modo Corrección</h3>
                    </div>
                    <p class="text-sm text-gray-600 mb-4">
                        Descarga un archivo compatible con el módulo <b>"Carga Nómina"</b>. Úsalo para editar nombres, cargos o el <b>Estatus de Nómina</b> y vuélvelo a subir.
                    </p>
                    
                    <form method="POST">
                        <input type="hidden" name="exportar" value="1">
                        <input type="hidden" name="preset_mode" value="nomina_reimportable">
                        <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded-lg shadow transition flex justify-center items-center gap-2">
                            <i class="fas fa-download"></i> Bajar Plantilla Maestra
                        </button>
                    </form>
                </div>
                <div class="bg-purple-50 px-5 py-2 text-xs text-purple-800 font-medium">
                    * Incluye columna "Estatus Nómina" para actualización masiva.
                </div>
            </div>

            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
                <h4 class="font-bold text-yellow-800 text-sm mb-2"><i class="fas fa-lightbulb"></i> ¿Cómo actualizar estatus?</h4>
                <ol class="list-decimal list-inside text-xs text-yellow-900 space-y-1">
                    <li>Descarga la <b>Plantilla Maestra</b>.</li>
                    <li>Busca la columna <b>ESTATUS NOMINA</b> (al final).</li>
                    <li>Escribe <b>"En Nomina"</b> o <b>"Fuera de Nomina"</b>.</li>
                    <li>Guarda y sube en <i>Configuración > Carga Nómina</i>.</li>
                </ol>
            </div>

        </div>
    </div>
</div>

<script>
function selectAll() {
    const checkboxes = document.querySelectorAll('input[name="columnas[]"]');
    const allChecked = Array.from(checkboxes).every(c => c.checked);
    checkboxes.forEach(c => c.checked = !allChecked);
}
</script>

<?php require 'layout_footer.php'; ?>