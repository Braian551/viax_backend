<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT id, nombre, email, tipo_usuario, empresa_id FROM usuarios WHERE nombre ILIKE '%Braian%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
