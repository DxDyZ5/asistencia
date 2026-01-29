<?php
// perfil_empleado.php - LISTA DE EMPLEADOS
require 'auth.php'; 
verificarPermiso(['admin', 'rrhh']);

// ELIMINAR
if (isset($_GET['borrar'])) {
    $id_borrar = $_GET['borrar'];
    try {
        $stmt = $pdo->prepare("DELETE FROM empleados WHERE id_reloj = ?");
        $stmt->execute([$id_borrar]);
    } catch (Exception $e) {}
}

// FILTROS
$termino = isset($_GET['q']) ? trim($_GET['q']) : '';
$mostrar_sin_cedula = isset($_GET['sin_cedula']) ? true : false; 

$sql = "SELECT * FROM empleados WHERE (nombre_completo LIKE :t OR cedula LIKE :t OR id_reloj LIKE :t OR departamento LIKE :t) AND nombre_completo IS NOT NULL AND TRIM(nombre_completo) != '' AND nombre_completo NOT LIKE 'INV%'";
if (!$mostrar_sin_cedula) $sql .= " AND cedula != '' AND cedula IS NOT NULL";
$sql .= " ORDER BY id_reloj DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute([':t' => "%$termino%"]);
$empleados = $stmt->fetchAll();

require 'layout_head.php';
?>

<div class="bg-white p-4 rounded-xl shadow mb-6 border border-gray-200">
    <form class="flex flex-col md:flex-row gap-4 justify-between items-center">
        <div class="flex-1 w-full relative">
            <input type="text" name="q" value="<?php echo htmlspecialchars($termino); ?>" placeholder="Buscar por Nombre, ID, Depto..." class="w-full pl-10 pr-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
        </div>
        <div class="flex items-center gap-2 bg-gray-50 px-4 py-2 rounded border">
            <input type="checkbox" name="sin_cedula" id="chk_cedula" value="1" onchange="this.form.submit()" <?php echo $mostrar_sin_cedula ? 'checked' : ''; ?>>
            <label for="chk_cedula" class="text-sm font-bold text-gray-600 cursor-pointer select-none">Ver Sin Cédula</label>
        </div>
        <a href="editar_empleado.php?id=new" class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg font-bold shadow flex items-center gap-2"><i class="fas fa-user-plus"></i> Nuevo</a>
    </form>
</div>

<div class="bg-white rounded-xl shadow-lg overflow-hidden border border-gray-200">
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-100 text-gray-500 uppercase text-xs font-bold">
                <tr><th class="px-6 py-3 text-left">Info Básica</th><th class="px-6 py-3 text-left">Datos RRHH</th><th class="px-6 py-3 text-center">Tipo</th><th class="px-6 py-3 text-center">Acciones</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($empleados as $emp): 
                    $ced_limpia = preg_replace('/[^0-9]/', '', $emp['cedula']);
                    $foto = "fotos/" . $ced_limpia . ".jpg";
                    if(!file_exists($foto)) $foto = "https://ui-avatars.com/api/?name=".urlencode($emp['nombre_completo']);
                ?>
                <tr class="hover:bg-blue-50 transition group cursor-pointer" onclick="window.location='editar_empleado.php?id=<?php echo $emp['id_reloj']; ?>'">
                    <td class="px-6 py-3">
                        <div class="flex items-center gap-3">
                            <img src="<?php echo $foto; ?>" class="w-10 h-10 rounded-full object-cover border">
                            <div>
                                <div class="font-bold text-gray-900 group-hover:text-blue-700"><?php echo htmlspecialchars($emp['nombre_completo']); ?></div>
                                <div class="text-xs text-gray-400 font-mono">ID: <?php echo $emp['id_reloj']; ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-3">
                        <div class="text-gray-700 font-medium"><?php echo htmlspecialchars($emp['departamento']); ?></div>
                        <div class="text-xs text-gray-500 flex gap-2"><span><i class="far fa-id-card"></i> <?php echo $emp['cedula'] ? $emp['cedula'] : '<span class="text-red-300 font-bold">S/C</span>'; ?></span></div>
                    </td>
                    <td class="px-6 py-3 text-center">
                        <?php if($emp['tipo_personal'] == 'planta'): ?><span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-[10px] font-bold border border-blue-200">PLANTA</span><?php else: ?><span class="bg-orange-100 text-orange-800 px-2 py-1 rounded text-[10px] font-bold border border-orange-200">EXTERNO</span><?php endif; ?>
                    </td>
                    <td class="px-6 py-3 text-center">
                        <a href="editar_empleado.php?id=<?php echo $emp['id_reloj']; ?>" class="text-blue-500 hover:text-blue-700 px-2"><i class="fas fa-edit fa-lg"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require 'layout_footer.php'; ?>