<?php
// carnets_panel.php
require 'auth.php'; 
verificarPermiso(['admin', 'carnet']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accion_solicitar'])) $pdo->prepare("UPDATE empleados SET solicitud_carnet = 1 WHERE id_reloj = ?")->execute([$_POST['id_emp']]);
    if (isset($_POST['accion_listo'])) $pdo->prepare("UPDATE empleados SET solicitud_carnet = 2 WHERE id_reloj = ?")->execute([$_POST['id_emp']]);
}

$pendientes = $pdo->query("SELECT * FROM empleados WHERE solicitud_carnet = 1 ORDER BY departamento ASC")->fetchAll();
$termino = $_GET['q'] ?? '';
$empleados_general = $pdo->query("SELECT * FROM empleados WHERE nombre_completo LIKE '%$termino%' LIMIT 20")->fetchAll();

require 'layout_head.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <!-- BANDEJA DE ENTRADA -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-lg border border-orange-200 overflow-hidden relative">
            <div class="absolute top-0 left-0 w-1 h-full bg-orange-500"></div>
            <div class="p-4 border-b border-gray-100 bg-orange-50 flex justify-between items-center">
                <h2 class="font-bold text-orange-800"><i class="fas fa-inbox mr-2"></i> Solicitudes</h2>
                <span class="bg-orange-600 text-white text-xs font-bold px-2 py-1 rounded-full"><?php echo count($pendientes); ?></span>
            </div>
            <div class="overflow-y-auto max-h-[600px] p-2 space-y-2 bg-gray-50">
                <?php foreach($pendientes as $p): ?>
                <div class="bg-white p-3 rounded-lg shadow-sm border border-gray-200">
                    <div class="font-bold text-sm text-gray-800"><?php echo $p['nombre_completo']; ?></div>
                    <div class="flex gap-2 mt-2">
                        <a href="carnets_diseno.php?id=<?php echo $p['id_reloj']; ?>" class="flex-1 bg-blue-600 text-white py-1 rounded text-xs text-center font-bold">Diseñar</a>
                        <form method="POST"><input type="hidden" name="id_emp" value="<?php echo $p['id_reloj']; ?>"><input type="hidden" name="accion_listo" value="1"><button class="bg-green-100 text-green-700 py-1 px-3 rounded text-xs font-bold"><i class="fas fa-check"></i></button></form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- DIRECTORIO -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow border border-gray-200 p-6">
            <form method="GET" class="flex gap-2 mb-4">
                <input type="text" name="q" value="<?php echo htmlspecialchars($termino); ?>" placeholder="Buscar..." class="w-full border rounded-lg px-4 py-2 text-sm bg-gray-50">
                <button type="submit" class="bg-slate-700 text-white px-6 py-2 rounded-lg font-bold text-sm">Buscar</button>
            </form>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase"><tr><th>Empleado</th><th>Estado</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach($empleados_general as $e): ?>
                    <tr>
                        <td class="px-4 py-3"><?php echo htmlspecialchars($e['nombre_completo']); ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php if($e['solicitud_carnet']==1) echo '<span class="text-orange-600 font-bold">Solicitado</span>'; 
                                  elseif($e['solicitud_carnet']==2) echo '<span class="text-green-600 font-bold">Entregado</span>'; 
                                  else echo '-'; ?>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="carnets_diseno.php?id=<?php echo $e['id_reloj']; ?>" class="bg-blue-100 text-blue-700 px-3 py-1 rounded text-xs font-bold">Ver Carnet</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require 'layout_footer.php'; ?>