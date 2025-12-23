<?php
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "=== ESTRUCTURA DE TABLA usuarios ===\n";
$stmt = $db->query("DESCRIBE usuarios");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "{$col['Field']} - {$col['Type']}\n";
}

echo "\n=== ESTRUCTURA DE TABLA detalles_conductor ===\n";
$stmt = $db->query("DESCRIBE detalles_conductor");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "{$col['Field']} - {$col['Type']}\n";
}
