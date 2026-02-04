<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'empresas_transporte' ORDER BY ordinal_position");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
?>
