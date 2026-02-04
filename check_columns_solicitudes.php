<?php
require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();

echo "=== Columnas de solicitudes_servicio ===\n";
$stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'solicitudes_servicio' ORDER BY ordinal_position");
foreach($stmt as $row) {
    echo "- " . $row['column_name'] . "\n";
}
