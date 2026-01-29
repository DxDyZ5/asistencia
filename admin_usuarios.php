<?php
// admin_usuarios.php - GESTIÓN DE USUARIOS Y PERMISOS (RBAC)
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require 'auth.php';

// SOLO ADMIN PUEDE ENTRAR AQUÍ
verificarPermiso(['admin']);

$mensaje = '';
$tipo_msg = '';

// --- ACCIONES DE BASE DE DATOS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. CREAR USUARIO
    if (isset($_POST['accion']) && $_POST['accion'] === 'crear') {
        $user = trim($_POST['usuario']);
        $pass = $_POST['password'];
        $rol  = $_POST['rol'];

        // Verificar si existe
        $stmt = $pdo->prepare("SELECT id FROM usuarios_admin WHERE usuario = ?");
        $stmt->execute([$user]);
        if ($stmt->fetch()) {
            $mensaje = "⚠ El usuario ya existe."; $tipo_msg = "error";
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios_admin (usuario, password, rol) VALUES (?, ?, ?)");
            if ($stmt->execute([$user, $hash, $rol])) {
                $mensaje = "✅ Usuario creado correctamente."; $tipo_msg = "success";
            }
        }
    }

    // 2. ELIMINAR USUARIO
    if (isset($_POST['accion']) && $_POST['accion'] === 'eliminar') {
        $id_borrar = $_POST['id_usuario'];
        // Evitar auto-borrado
        if ($id_borrar == $_SESSION['usuario_id']) {
            $mensaje = "⛔ No puedes eliminar tu propio usuario."; $tipo_msg = "error";
        } else {
            $pdo->prepare("DELETE FROM usuarios_admin WHERE id = ?")->execute([$id_borrar]);
            $mensaje = "🗑 Usuario eliminado."; $tipo_msg = "yellow";
        }
    }

    // 3. EDITAR USUARIO (Cambiar Rol o Password)
    if (isset($_POST['accion']) && $_POST['accion'] === 'editar') {
        $id_edit = $_POST['id_usuario'];
        $rol_edit = $_POST['rol'];
        $pass_edit = $_POST['password'];

        if (!empty($pass_edit)) {
            // Si escribió contraseña, actualizamos todo
            $hash = password_hash($pass_edit, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios_admin SET rol = ?, password = ? WHERE id = ?");
            $stmt->execute([$rol_edit, $hash, $id_edit]);
        } else {
            // Si no, solo actualizamos el rol
            $stmt = $pdo->prepare("UPDATE usuarios_admin SET rol = ? WHERE id = ?");
            $stmt->execute([$rol_edit, $id_edit]);
        }
        $mensaje = "✅ Usuario actualizado."; $tipo_msg = "success";
    }
}

// --- OBTENER LISTA DE USUARIOS ---
$usuarios = $pdo->query("SELECT * FROM usuarios_admin ORDER BY id ASC")->fetchAll();

require 'layout_head.php';
?>

<div class="max-w-7xl mx-auto pb-20">
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800"><i class="fas fa-user-shield text-blue-600 mr-2"></i>Gestión de Accesos</h1>
            <p class="text-sm text-gray-500">Administra quién puede entrar al sistema y qué puede ver.</p>
        </div>
        <button onclick="document.getElementById('modalCrear').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold shadow transition">
            <i class="fas fa-plus mr-2"></i> Nuevo Usuario
        </button>
    </div>

    <?php if($mensaje): ?>
        <div class="mb-6 p-4 rounded-lg border shadow-sm <?php echo $tipo_msg=='success'?'bg-green-100 border-green-400 text-green-800':($tipo_msg=='yellow'?'bg-yellow-100 border-yellow-400 text-yellow-800':'bg-red-100 border-red-400 text-red-800'); ?>">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <div class="lg:col-span-2 bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
            <div class="bg-slate-50 px-6 py-4 border-b border-gray-200">
                <h3 class="font-bold text-gray-700">Usuarios del Sistema</h3>
            </div>
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-100 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-6 py-3">Usuario</th>
                        <th class="px-6 py-3">Rol / Nivel</th>
                        <th class="px-6 py-3 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach($usuarios as $u): 
                        $badge = match($u['rol']) {
                            'admin' => 'bg-red-100 text-red-800 border-red-200',
                            'rrhh' => 'bg-blue-100 text-blue-800 border-blue-200',
                            'carnet' => 'bg-purple-100 text-purple-800 border-purple-200',
                            default => 'bg-gray-100 text-gray-800'
                        };
                    ?>
                    <tr class="hover:bg-slate-50 group">
                        <td class="px-6 py-4 font-bold text-gray-700 flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center text-slate-500">
                                <i class="fas fa-user"></i>
                            </div>
                            <?php echo htmlspecialchars($u['usuario']); ?>
                            <?php if($u['id'] == $_SESSION['usuario_id']): ?>
                                <span class="text-[10px] bg-green-500 text-white px-1.5 py-0.5 rounded ml-2">TÚ</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 rounded text-xs font-bold border <?php echo $badge; ?> uppercase">
                                <?php echo $u['rol']; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <button onclick="editarUsuario('<?php echo $u['id']; ?>', '<?php echo $u['usuario']; ?>', '<?php echo $u['rol']; ?>')" class="text-blue-600 hover:text-blue-800 mr-3" title="Editar">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if($u['id'] != $_SESSION['usuario_id']): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este usuario?');">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id_usuario" value="<?php echo $u['id']; ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded-xl shadow border border-gray-200 p-6 h-fit">
            <h3 class="font-bold text-gray-700 mb-4 flex items-center"><i class="fas fa-key text-yellow-500 mr-2"></i> Matriz de Permisos</h3>
            
            <div class="space-y-4">
                <div class="border rounded-lg p-3 border-red-100 bg-red-50">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-xs font-bold bg-red-200 text-red-800 px-2 py-1 rounded uppercase">Admin</span>
                        <i class="fas fa-check-double text-red-400"></i>
                    </div>
                    <ul class="text-xs text-gray-600 list-disc pl-4 space-y-1">
                        <li><b>Acceso Total</b> al sistema.</li>
                        <li>Crear/Eliminar Usuarios Admin.</li>
                        <li>Fusión y Limpieza de BD.</li>
                        <li>Configuración Global.</li>
                    </ul>
                </div>

                <div class="border rounded-lg p-3 border-blue-100 bg-blue-50">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-xs font-bold bg-blue-200 text-blue-800 px-2 py-1 rounded uppercase">RRHH</span>
                        <i class="fas fa-users text-blue-400"></i>
                    </div>
                    <ul class="text-xs text-gray-600 list-disc pl-4 space-y-1">
                        <li>Gestión de Empleados (Editar/Ver).</li>
                        <li>Control de Asistencia y Justificaciones.</li>
                        <li>Generación de Carnets.</li>
                        <li>Carga de Archivos (Reloj/Nómina).</li>
                        <li class="text-red-400">Sin acceso a Gestión de Usuarios.</li>
                    </ul>
                </div>

                <div class="border rounded-lg p-3 border-purple-100 bg-purple-50">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-xs font-bold bg-purple-200 text-purple-800 px-2 py-1 rounded uppercase">Carnet</span>
                        <i class="fas fa-id-card text-purple-400"></i>
                    </div>
                    <ul class="text-xs text-gray-600 list-disc pl-4 space-y-1">
                        <li>Solo visualización de perfiles.</li>
                        <li>Impresión de Carnets.</li>
                        <li class="text-red-400">Sin acceso a nómina o edición.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="modalCrear" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white p-6 rounded-xl shadow-2xl w-96 transform transition-all scale-100">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Nuevo Usuario</h3>
        <form method="POST">
            <input type="hidden" name="accion" value="crear">
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Usuario</label>
                    <input type="text" name="usuario" required class="w-full border rounded p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Contraseña</label>
                    <input type="password" name="password" required class="w-full border rounded p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Rol</label>
                    <select name="rol" class="w-full border rounded p-2 text-sm bg-white">
                        <option value="rrhh">RRHH (Gestión)</option>
                        <option value="admin">Admin (Total)</option>
                        <option value="carnet">Carnet (Limitado)</option>
                    </select>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('modalCrear').classList.add('hidden')" class="px-4 py-2 text-gray-500 hover:text-gray-700 text-sm font-bold">Cancelar</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-bold">Crear</button>
            </div>
        </form>
    </div>
</div>

<div id="modalEditar" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white p-6 rounded-xl shadow-2xl w-96">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Editar Usuario: <span id="editUserLabel" class="text-blue-600"></span></h3>
        <form method="POST">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="id_usuario" id="editId">
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Nueva Contraseña (Opcional)</label>
                    <input type="password" name="password" placeholder="Dejar vacío para no cambiar" class="w-full border rounded p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Rol</label>
                    <select name="rol" id="editRol" class="w-full border rounded p-2 text-sm bg-white">
                        <option value="rrhh">RRHH</option>
                        <option value="admin">Admin</option>
                        <option value="carnet">Carnet</option>
                    </select>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('modalEditar').classList.add('hidden')" class="px-4 py-2 text-gray-500 hover:text-gray-700 text-sm font-bold">Cancelar</button>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-bold">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
    function editarUsuario(id, usuario, rol) {
        document.getElementById('editId').value = id;
        document.getElementById('editUserLabel').innerText = usuario;
        document.getElementById('editRol').value = rol;
        document.getElementById('modalEditar').classList.remove('hidden');
    }
</script>

<?php require 'layout_footer.php'; ?>