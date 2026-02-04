<?php
require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();

echo "=== Columnas de detalles_conductor ===\n";
$stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'detalles_conductor' ORDER BY ordinal_position");
foreach($stmt as $row) {
    echo "- " . $row['column_name'] . "\n";
}
