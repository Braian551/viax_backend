<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT id, fecha_registro FROM usuarios WHERE id = 277");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
?>
