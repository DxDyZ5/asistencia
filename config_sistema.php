<?php
require 'auth.php';
verificarPermiso(['admin']); // SOLO ADMIN

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Procesar logo
    $logo_path = $sys_logo; // Mantener anterior por defecto
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg'])) {
            $dir = 'uploads/system/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $new_name = 'logo_sys_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $dir . $new_name)) {
                $logo_path = $dir . $new_name;
            }
        }
    }

    $new_name = $_POST['nombre_sistema'];
    $new_foot = $_POST['pie_pagina'];

    try {
        $pdo->beginTransaction();
        $pdo->prepare("REPLACE INTO configuracion_sistema (clave, valor) VALUES ('nombre_sistema', ?)")->execute([$new_name]);
        $pdo->prepare("REPLACE INTO configuracion_sistema (clave, valor) VALUES ('pie_pagina', ?)")->execute([$new_foot]);
        $pdo->prepare("REPLACE INTO configuracion_sistema (clave, valor) VALUES ('logo_url', ?)")->execute([$logo_path]);
        $pdo->commit();
        
        // Actualizar variables en tiempo real
        $sys_nombre = $new_name;
        $sys_footer = $new_foot;
        $sys_logo = $logo_path;
        
        $mensaje = "✅ Configuración actualizada correctamente.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

require 'layout_head.php';
?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-2">
        <i class="fas fa-cogs text-slate-500"></i> Configuración del Sistema
    </h1>

    <?php if($mensaje): ?>
        <div class="mb-6 p-4 rounded-lg bg-green-100 text-green-800 border border-green-200">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
        <div class="p-6 border-b border-gray-100 bg-slate-50">
            <h2 class="font-bold text-slate-700">Personalización de Marca</h2>
            <p class="text-xs text-slate-500">Define la identidad visual del entorno CRT-RRHH.</p>
        </div>
        
        <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- LOGO -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">Logotipo del Sistema</label>
                    <div class="flex items-center gap-4 mb-3">
                        <div class="w-20 h-20 bg-gray-100 border rounded flex items-center justify-center overflow-hidden">
                            <?php if($sys_logo): ?>
                                <img src="<?php echo $sys_logo; ?>" class="w-full h-full object-contain">
                            <?php else: ?>
                                <span class="text-gray-300 text-xs">Sin Logo</span>
                            <?php endif; ?>
                        </div>
                        <input type="file" name="logo_file" class="text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    </div>
                    <p class="text-xs text-gray-400">Recomendado: PNG transparente o SVG. Máx 2MB.</p>
                </div>

                <!-- DATOS -->
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Nombre del Sistema</label>
                        <input type="text" name="nombre_sistema" value="<?php echo htmlspecialchars($sys_nombre); ?>" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Texto Pie de Página</label>
                        <input type="text" name="pie_pagina" value="<?php echo htmlspecialchars($sys_footer); ?>" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>
            </div>

            <div class="pt-6 border-t border-gray-100 flex justify-end">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-bold shadow transition flex items-center gap-2">
                    <i class="fas fa-save"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<?php require 'layout_footer.php'; ?>