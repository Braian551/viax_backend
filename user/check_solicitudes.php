<?php
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "=== ESTRUCTURA DE TABLA solicitudes_servicio ===\n";
$stmt = $db->query("DESCRIBE solicitudes_servicio");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "{$col['Field']} - {$col['Type']}\n";
}
