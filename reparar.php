<?php
// reparar.php - Corrección de Rol y Restablecimiento de Admin
session_start(); // Iniciar sesión para poder destruirla

// Credenciales
$host = 'localhost';
$db   = 'reynoteja_control_asistencia';
$user = 'reynoteja_carlos';
$pass = 'M22300435397'; 

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. Asegurar tabla
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios_admin (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        rol VARCHAR(20) NOT NULL DEFAULT 'rrhh'
    )");

    // 2. Limpiar usuario corrupto o incorrecto
    $pdo->exec("DELETE FROM usuarios_admin WHERE usuario = 'admin'");

    // 3. Crear usuario con el ROL CORRECTO ('admin')
    // El sistema espera 'admin', no 'superadmin'
    $password_segura = password_hash('admin123', PASSWORD_DEFAULT);
    $sql = "INSERT INTO usuarios_admin (usuario, password, rol) VALUES ('admin', ?, 'admin')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$password_segura]);

    // 4. DESTRUIR SESIÓN ACTUAL (Crucial para eliminar el error de 'superadmin' en caché)
    session_unset();
    session_destroy();

    // Mensaje de éxito
    echo "<div style='font-family: sans-serif; text-align: center; margin-top: 50px; padding: 20px;'>";
    echo "<h1 style='color:green; font-size: 24px;'>✅ USUARIO CORREGIDO</h1>";
    echo "<p style='font-size: 18px;'>Se ha cambiado el rol de 'superadmin' a <b>'admin'</b>.</p>";
    echo "<p>La sesión anterior ha sido cerrada para aplicar los cambios.</p>";
    echo "<div style='background: #f3f4f6; display: inline-block; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
    echo "Usuario: <b>admin</b><br>";
    echo "Contraseña: <b>admin123</b>";
    echo "</div><br>";
    echo "<a href='index.php' style='background: #2563eb; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;'>Iniciar Sesión Ahora</a>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<h1 style='color:red; text-align:center;'>❌ ERROR</h1>";
    echo "<p style='text-align:center;'>" . $e->getMessage() . "</p>";
}
?>