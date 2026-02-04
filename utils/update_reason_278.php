<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

// Update to match the user's screenshot
$razon = "falta documentos";
$conductor_id = 278;

$stmt = $db->prepare("UPDATE detalles_conductor SET razon_rechazo = :razon WHERE usuario_id = :id");
$stmt->execute([':razon' => $razon, ':id' => $conductor_id]);

echo "Updated reason for user 278 to: '$razon'\n";
?>
