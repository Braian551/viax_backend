<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

$stmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'solicitudes_vinculacion_conductor'");
$stmt->execute();
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Columns: " . implode(', ', $cols) . "\n";
?>
