<?php
session_start();
$host = 'localhost';
$db   = 'reynoteja_control_asistencia';
$user = 'reynoteja_carlos';
$pass = 'M22300435397'; // <--- ¡CONTRASEÑA REAL!

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
} catch (PDOException $e) { die("Error DB"); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = $_POST['usuario'];
    $p = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM usuarios_admin WHERE usuario = ?");
    $stmt->execute([$u]);
    $admin = $stmt->fetch();

    // Verificamos password (admin123 es el por defecto)
    if ($admin && password_verify($p, $admin['password'])) {
        $_SESSION['usuario_id'] = $admin['id'];
        $_SESSION['usuario'] = $admin['usuario'];
        $_SESSION['rol'] = $admin['rol'];
        header("Location: index.php");
        exit;
    } else {
        $error = "Usuario o contraseña incorrectos";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login - Asistencia</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-800 h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-lg w-96">
        <h2 class="text-2xl font-bold mb-6 text-center text-gray-700">Acceso al Sistema</h2>
        <?php if($error): ?><div class="bg-red-100 text-red-700 p-2 rounded mb-4 text-sm"><?php echo $error; ?></div><?php endif; ?>
        <form method="POST">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Usuario</label>
                <input type="text" name="usuario" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-bold mb-2">Contraseña</label>
                <input type="password" name="password" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 rounded hover:bg-blue-700">Entrar</button>
        </form>
        <p class="mt-4 text-xs text-center text-gray-400">Credenciales por defecto: admin / admin123</p>
    </div>
</body>
</html>