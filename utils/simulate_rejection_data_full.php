<?php
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();
$userId = 278; // User we were working with

$stmt = $db->prepare("SELECT * FROM detalles_conductor WHERE usuario_id = :id");
$stmt->bindParam(':id', $userId);
$stmt->execute();
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

print_r($profile);
?>
