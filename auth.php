<?php
// auth.php - SEGURIDAD + CARGA DE DATOS DE USUARIO
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. CONEXI�0�7N A BASE DE DATOS (Centralizada)
if (!isset($pdo)) {
    try {
        $host = 'localhost';
        $db   = 'reynoteja_control_asistencia';
        $user = 'reynoteja_carlos';
        $pass = 'M22300435397'; 
        $charset = 'utf8mb4';
        
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) { 
        die("Error de conexi��n: " . $e->getMessage()); 
    }
}

// 2. VERIFICAR LOGIN
if (!isset($_SESSION['usuario_id']) || empty($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

// 3. CARGAR DATOS DEL USUARIO (�0�3ESTO ES LO QUE FALTABA!)
// Buscamos los datos del usuario logueado para que el men�� pueda usar $usuario_actual['usuario']
try {
    $stmtAuth = $pdo->prepare("SELECT * FROM usuarios_admin WHERE id = ?");
    $stmtAuth->execute([$_SESSION['usuario_id']]);
    $usuario_actual = $stmtAuth->fetch();

    if (!$usuario_actual) {
        // Si el usuario fue borrado de la BD pero tiene sesi��n, sacarlo.
        session_destroy();
        header("Location: login.php");
        exit();
    }

    // Variables globales para que layout_head.php las use
    $nombre_usuario_sesion = $usuario_actual['usuario'];
    $rol_usuario_sesion = $usuario_actual['rol'];

} catch (Exception $e) {
    die("Error de sesi��n: " . $e->getMessage());
}

// 4. FUNCI�0�7N DE PERMISOS
function verificarPermiso($roles_permitidos = []) {
    global $usuario_actual; // Usar la variable cargada arriba
    
    if (isset($usuario_actual['rol']) && $usuario_actual['rol'] === 'admin') {
        return true; 
    }

    if (!empty($roles_permitidos)) {
        if (!in_array($usuario_actual['rol'], $roles_permitidos)) {
            die("<div style='font-family:sans-serif; text-align:center; padding:50px;'>
                    <h1>�7�4 Acceso Denegado</h1>
                    <p>No tienes permisos para ver esta secci��n.</p>
                    <a href='index.php'>Volver al Inicio</a>
                 </div>");
        }
    }
}
?>