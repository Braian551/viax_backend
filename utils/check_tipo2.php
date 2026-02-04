<?php
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();

$stmt = $db->query("SELECT tipo_usuario FROM usuarios WHERE id = 277");
echo "tipo_usuario: " . $stmt->fetchColumn() . "\n";
?>
