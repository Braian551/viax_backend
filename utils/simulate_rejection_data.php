<?php
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();
$userId = 278; // User we were working with

$stmt = $db->prepare("SELECT * FROM detalles_conductor WHERE usuario_id = :id");
$stmt->bindParam(':id', $userId);
$stmt->execute();
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Estado: " . $profile['estado_aprobacion'] . "\n";
echo "Razon: " . $profile['razon_rechazo'] . "\n";
echo "Data dump: \n";
print_r($profile);
?>
