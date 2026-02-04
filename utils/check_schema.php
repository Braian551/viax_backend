<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

echo "--- detalles_conductor ---\n";
$stmt = $db->query("DESCRIBE detalles_conductor");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- solicitudes_vinculacion_conductor ---\n";
$stmt = $db->query("DESCRIBE solicitudes_vinculacion_conductor");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
