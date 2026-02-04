<?php
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

// List all tables
$stmt = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Tables:\n";
print_r($tables);

// Check documentos_conductor structure
$stmt = $db->query("
    SELECT column_name, data_type 
    FROM information_schema.columns 
    WHERE table_name = 'documentos_conductor'
");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n\ndocumentos_conductor columns:\n";
print_r($columns);

// Check documentos_conductor_historial structure
$stmt = $db->query("
    SELECT column_name, data_type 
    FROM information_schema.columns 
    WHERE table_name = 'documentos_conductor_historial'
");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n\ndocumentos_conductor_historial columns:\n";
print_r($columns);
