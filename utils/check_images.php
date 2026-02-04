<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$conductor_id = 278; 
$stmt = $db->prepare("SELECT foto_vehiculo, soat_foto_url, licencia_foto_url FROM detalles_conductor WHERE usuario_id = :id");
$stmt->bindParam(':id', $conductor_id);
$stmt->execute();
print_r($stmt->fetch(PDO::FETCH_ASSOC));
?>
