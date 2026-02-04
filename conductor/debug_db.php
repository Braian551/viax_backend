<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

$uid = 6; // From user logs

// Check usuarios
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo "User: " . json_encode($user) . "\n\n";

// Check detalles_conductor
$stmt = $db->prepare("SELECT * FROM detalles_conductor WHERE usuario_id = ?");
$stmt->execute([$uid]);
$det = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Detalles: " . json_encode($det) . "\n\n";

// Check documentos_verificacion
$stmt = $db->prepare("SELECT * FROM documentos_verificacion WHERE conductor_id = ?");
$stmt->execute([$uid]);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Docs: " . json_encode($docs) . "\n\n";
?>
