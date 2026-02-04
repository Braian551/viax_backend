<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT * FROM empresas_transporte WHERE id = 1");
print_r($stmt->fetch(PDO::FETCH_ASSOC));

echo "\nUsers linked to empresa 1:\n";
$stmt = $db->query("SELECT id, nombre, email, tipo_usuario, empresa_id FROM usuarios WHERE empresa_id = 1");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
