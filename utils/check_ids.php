<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT id, nombre, tipo_usuario FROM usuarios WHERE id IN (25, 254, 277)");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
