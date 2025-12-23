<?php
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$query = "SELECT * FROM paradas_solicitud";
$stmt = $db->prepare($query);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total rows: " . count($rows) . "\n";
print_r($rows);
?>
