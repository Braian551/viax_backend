<?php
require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();
$stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'usuarios' ORDER BY ordinal_position");
echo "Columnas en tabla usuarios:\n";
foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
    echo "- $col\n";
}
