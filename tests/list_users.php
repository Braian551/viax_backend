<?php
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$query = "SELECT id, nombre, email, tipo_usuario FROM usuarios LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($users);
?>
