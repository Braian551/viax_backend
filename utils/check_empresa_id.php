<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'detalles_conductor' AND column_name = 'empresa_id'");
$col = $stmt->fetchColumn();
echo $col ? "Found: $col" : "Not Found";
?>
