<?php
// Configuración para XAMPP LOCAL (PostgreSQL)
$host     = 'localhost';
$port     = '5432'; 
$dbname   = 'sistema_titulacion'; 
$user     = 'postgres'; // El usuario por defecto de tu pgAdmin local
$password = 'marco'; // La contraseña que pusiste al instalar PostgreSQL

try {
    // Nota: Eliminamos 'sslmode=require' porque en local no suele ser necesario
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    // echo "Conexión local exitosa"; 
} catch (PDOException $e) {
    die("Error de conexión local: " . $e->getMessage());
}

?>