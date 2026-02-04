<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT id, nombre, email, tipo_usuario, empresa_id FROM usuarios WHERE empresa_id = 1 OR id IN (SELECT id FROM usuarios WHERE tipo_usuario = 'empresa')");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
