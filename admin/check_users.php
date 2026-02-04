<?php
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();
$stmt = $db->query("SELECT id, nombre, tipo_usuario FROM usuarios WHERE id IN (6, 7, 11) ORDER BY id");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
