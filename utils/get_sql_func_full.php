<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->prepare("SELECT routine_definition FROM information_schema.routines WHERE routine_name = 'rechazar_vinculacion_conductor'");
$stmt->execute();
$def = $stmt->fetchColumn();
echo $def;
?>
