<?php
// analisis_asistencia.php
require 'auth.php'; 
verificarPermiso(['admin', 'rrhh']);

$pdo->exec("CREATE TABLE IF NOT EXISTS excepciones_asistencia (id INT AUTO_INCREMENT PRIMARY KEY, id_empleado_reloj INT NOT NULL, tipo ENUM('observacion', 'justificado', 'permanente') NOT NULL, fecha_inicio DATE NULL, fecha_fin DATE NULL, motivo VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

$msg_excepcion = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['agregar_excepcion'])) {
        $id_emp = $_POST['id_empleado_ex']; $tipo = $_POST['tipo_ex']; $motivo = trim($_POST['motivo_ex']);
        $f_ini_ex = !empty($_POST['f_ini_ex']) ? $_POST['f_ini_ex'] : null;
        $f_fin_ex = !empty($_POST['f_fin_ex']) ? $_POST['f_fin_ex'] : null;
        if ($tipo == 'permanente') { $f_ini_ex = null; $f_fin_ex = null; }
        if ($id_emp && $tipo) {
            $stmt = $pdo->prepare("INSERT INTO excepciones_asistencia (id_empleado_reloj, tipo, fecha_inicio, fecha_fin, motivo) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$id_emp, $tipo, $f_ini_ex, $f_fin_ex, $motivo])) $msg_excepcion = "✅ Guardado."; else $msg_excepcion = "❌ Error.";
        }
    }
    if (isset($_POST['borrar_excepcion'])) {
        $pdo->prepare("DELETE FROM excepciones_asistencia WHERE id = ?")->execute([$_POST['id_excepcion_del']]);
        $msg_excepcion = "🗑 Eliminado.";
    }
}

$f_inicio = $_GET['f_inicio'] ?? date('Y-m-01');
$f_fin    = $_GET['f_fin'] ?? date('Y-m-d');
$id_sel   = $_GET['id_seleccionado'] ?? null;

$filtro_total = " AND (e.notas_nomina IS NULL OR (e.notas_nomina NOT LIKE '%SUSPENDIDO%' AND e.notas_nomina NOT LIKE '%CANCELADO%')) AND e.cedula IS NOT NULL AND e.cedula != '' AND e.id_reloj NOT IN (SELECT id_empleado_reloj FROM excepciones_asistencia WHERE tipo = 'permanente' OR (fecha_inicio <= '$f_fin' AND fecha_fin >= '$f_inicio')) ";

$feriados_db = $pdo->query("SELECT fecha, descripcion FROM feriados")->fetchAll(PDO::FETCH_KEY_PAIR);
$empleados_lista = $pdo->query("SELECT id_reloj, nombre_completo FROM empleados WHERE nombre_completo IS NOT NULL ORDER BY nombre_completo ASC")->fetchAll();
$lista_excepciones_activas = $pdo->query("SELECT ex.*, e.nombre_completo FROM excepciones_asistencia ex JOIN empleados e ON ex.id_empleado_reloj = e.id_reloj ORDER BY ex.created_at DESC")->fetchAll();

$sqlAusencias = "SELECT e.id_reloj, e.nombre_completo, COUNT(DISTINCT a.fecha) as dias_asistidos FROM empleados e LEFT JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj AND a.fecha BETWEEN '$f_inicio' AND '$f_fin' WHERE e.tipo_personal != 'externo' $filtro_total GROUP BY e.id_reloj ORDER BY dias_asistidos ASC LIMIT 20";
$lista_ausencias = $pdo->query($sqlAusencias)->fetchAll();

require 'layout_head.php';
?>

<?php if($msg_excepcion): ?><div class="mb-4 p-3 bg-green-100 text-green-800 rounded"><?php echo $msg_excepcion; ?></div><?php endif; ?>

<div class="bg-white p-4 rounded-xl shadow-sm border border-gray-200 mb-8 flex flex-col md:flex-row justify-between items-center gap-4">
    <h1 class="text-2xl font-bold text-gray-800">Análisis Avanzado</h1>
    <form method="GET" class="flex items-center gap-2">
        <input type="date" name="f_inicio" value="<?php echo $f_inicio; ?>" class="border rounded p-2 text-sm">
        <input type="date" name="f_fin" value="<?php echo $f_fin; ?>" class="border rounded p-2 text-sm">
        <button type="submit" class="bg-indigo-600 text-white p-2 rounded-lg shadow"><i class="fas fa-sync-alt"></i></button>
    </form>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="bg-white rounded-xl shadow border border-red-100 h-[400px] flex flex-col">
        <div class="bg-red-50 p-3 font-bold text-red-800">Inasistencias Recurrentes</div>
        <div class="overflow-y-auto flex-grow p-0">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-red-50">
                    <?php foreach($lista_ausencias as $r): ?>
                    <tr class="hover:bg-red-50 cursor-pointer" onclick="window.location='historial.php?id=<?php echo $r['id_reloj']; ?>'">
                        <td class="px-4 py-2 font-bold"><?php echo htmlspecialchars($r['nombre_completo']); ?></td>
                        <td class="px-4 py-2 text-right"><span class="bg-red-100 text-red-700 px-2 rounded"><?php echo $r['dias_asistidos']; ?> días</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow border border-gray-300 h-[400px] flex flex-col">
        <div class="bg-gray-100 p-3 font-bold text-gray-700">Gestión de Excepciones</div>
        <div class="p-4 overflow-y-auto">
            <form method="POST" class="mb-4 bg-gray-50 p-3 rounded border">
                <input type="hidden" name="agregar_excepcion" value="1">
                <div class="grid grid-cols-2 gap-2 mb-2">
                    <input list="empleados_list" name="id_empleado_ex" class="border rounded p-1 text-xs w-full" placeholder="Empleado..." required>
                    <select name="tipo_ex" class="border rounded p-1 text-xs w-full"><option value="justificado">Justificado</option><option value="permanente">Permanente</option></select>
                </div>
                <input type="text" name="motivo_ex" class="border rounded p-1 text-xs w-full mb-2" placeholder="Motivo" required>
                <button class="bg-blue-600 text-white text-xs py-1 px-3 rounded w-full">Agregar</button>
            </form>
            <datalist id="empleados_list"><?php foreach($empleados_lista as $e): ?><option value="<?php echo $e['id_reloj']; ?>"><?php echo $e['nombre_completo']; ?></option><?php endforeach; ?></datalist>
            
            <table class="w-full text-xs">
                <tbody class="divide-y">
                    <?php foreach($lista_excepciones_activas as $ex): ?>
                    <tr>
                        <td class="p-2"><?php echo htmlspecialchars($ex['nombre_completo']); ?></td>
                        <td class="p-2"><span class="bg-gray-200 px-1 rounded"><?php echo $ex['tipo']; ?></span></td>
                        <td class="p-2 text-right">
                            <form method="POST" onsubmit="return confirm('¿Borrar?');">
                                <input type="hidden" name="borrar_excepcion" value="1"><input type="hidden" name="id_excepcion_del" value="<?php echo $ex['id']; ?>">
                                <button class="text-red-500"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require 'layout_footer.php'; ?>