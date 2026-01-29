<?php
require 'auth.php'; // Protegido
if (!isSuperAdmin()) { die("Acceso Denegado. Solo Super Admin."); }

// Conexión DB
$host = 'localhost';
$db   = 'reynoteja_control_asistencia';
$user = 'reynoteja_carlos';
$pass = 'M22300435397'; // <--- ¡PON TU CONTRASEÑA!

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);

$mensaje = '';

// 1. CREAR ADMIN
if (isset($_POST['crear_admin'])) {
    $nuevo_user = $_POST['nuevo_user'];
    $nuevo_pass = password_hash($_POST['nuevo_pass'], PASSWORD_DEFAULT);
    $nuevo_rol = $_POST['nuevo_rol'];
    
    $stmt = $pdo->prepare("INSERT INTO usuarios_admin (usuario, password, rol) VALUES (?, ?, ?)");
    if ($stmt->execute([$nuevo_user, $nuevo_pass, $nuevo_rol])) {
        $mensaje = "Usuario creado correctamente.";
    } else {
        $mensaje = "Error: El usuario ya existe.";
    }
}

// 2. BORRAR ADMIN
if (isset($_GET['borrar'])) {
    $id_borrar = $_GET['borrar'];
    if ($id_borrar != $_SESSION['usuario_id']) { // No te borres a ti mismo
        $pdo->prepare("DELETE FROM usuarios_admin WHERE id = ?")->execute([$id_borrar]);
    }
}

// 3. LIMPIAR BASE DE DATOS (PELIGRO)
if (isset($_POST['limpiar_db'])) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE asistencia");
    $pdo->exec("TRUNCATE TABLE empleados");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    $mensaje = "Base de datos (Empleados y Asistencias) vaciada correctamente.";
}

$admins = $pdo->query("SELECT * FROM usuarios_admin")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Super Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">Panel de Control (Super Admin)</h1>
            <a href="index.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">Volver al Sistema</a>
        </div>

        <?php if($mensaje): ?>
            <div class="bg-green-100 text-green-800 p-4 rounded mb-6 border border-green-200"><?php echo $mensaje; ?></div>
        <?php endif; ?>

        <div class="bg-white p-6 rounded shadow mb-8">
            <h2 class="text-xl font-bold mb-4">Gestión de Administradores</h2>
            
            <table class="w-full mb-6 text-sm text-left">
                <thead class="bg-gray-50">
                    <tr><th>Usuario</th><th>Rol</th><th>Acción</th></tr>
                </thead>
                <tbody>
                    <?php foreach($admins as $a): ?>
                    <tr class="border-b">
                        <td class="py-2"><?php echo $a['usuario']; ?></td>
                        <td class="py-2"><span class="bg-blue-100 text-blue-800 px-2 rounded text-xs"><?php echo $a['rol']; ?></span></td>
                        <td class="py-2">
                            <?php if($a['id'] != $_SESSION['usuario_id']): ?>
                                <a href="?borrar=<?php echo $a['id']; ?>" class="text-red-600 hover:underline" onclick="return confirm('¿Seguro?')">Eliminar</a>
                            <?php else: ?>
                                <span class="text-gray-400">Tú</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="POST" class="flex gap-4 items-end bg-gray-50 p-4 rounded">
                <div>
                    <label class="block text-xs font-bold text-gray-500">Nuevo Usuario</label>
                    <input type="text" name="nuevo_user" required class="border rounded p-1">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500">Contraseña</label>
                    <input type="password" name="nuevo_pass" required class="border rounded p-1">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500">Rol</label>
                    <select name="nuevo_rol" class="border rounded p-1">
                        <option value="admin">Admin (Normal)</option>
                        <option value="superadmin">Super Admin</option>
                    </select>
                </div>
                <button type="submit" name="crear_admin" class="bg-blue-600 text-white px-4 py-1 rounded font-bold">Crear</button>
            </form>
        </div>

        <div class="bg-red-50 p-6 rounded shadow border border-red-200">
            <h2 class="text-xl font-bold text-red-800 mb-2">Zona de Peligro</h2>
            <p class="text-red-600 text-sm mb-4">Esta acción borrará TODOS los empleados y TODOS los registros de asistencia. Los usuarios administradores NO se borrarán.</p>
            <form method="POST" onsubmit="return confirm('¿ESTÁS COMPLETAMENTE SEGURO? ESTO NO SE PUEDE DESHACER.');">
                <button type="submit" name="limpiar_db" class="bg-red-600 text-white font-bold px-6 py-2 rounded hover:bg-red-700">
                    ⚠ BORRAR TODO (RESET)
                </button>
            </form>
        </div>
    </div>
</body>
</html>