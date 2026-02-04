<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt->execute(['leidyandrea315@gmail.com']);
echo "ID: " . $stmt->fetchColumn() . "\n";
?>
