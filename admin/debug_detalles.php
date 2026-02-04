<?php
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Check detalles_conductor structure and data
$stmt = $db->query("
    SELECT column_name, data_type 
    FROM information_schema.columns 
    WHERE table_name = 'detalles_conductor'
");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "detalles_conductor columns:\n";
print_r($columns);

$stmt = $db->query("SELECT * FROM detalles_conductor LIMIT 5");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n\ndetalles_conductor data:\n";
print_r($data);

// Check if conductor_id 6 exists in historial
$stmt = $db->query("SELECT DISTINCT conductor_id FROM documentos_conductor_historial");
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "\n\nExisting conductor_ids in historial:\n";
print_r($ids);
