<?php
/**
 * Conexión a PostgreSQL - Render
 */

$host     = 'dpg-d5g13m95pdvs73cc3u0g-a.oregon-postgres.render.com';
$port     = '5432';
$dbname   = 'sistema_titulacion';
$user     = 'marco_admin';
$password = 'M1uKfdB41kv3RGZUQcBTEhRRtVuHjAMu';

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Opcional: mensaje de prueba
    // echo "✅ Conexión exitosa a PostgreSQL (Render)";

} catch (PDOException $e) {
    die('❌ Error de conexión a PostgreSQL: ' . $e->getMessage());
}
