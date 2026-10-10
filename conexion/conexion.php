<?php
$host = '127.0.0.1';
$port = '3306';
$db = 'administracion';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$pdo = null;
$error_msg = '';
$success_msg = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    $error_msg("Error de conexión: " . $e->getMessage());
}
