<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

$u = $db->query("SELECT id FROM usuarios WHERE id = 254")->fetch(PDO::FETCH_ASSOC);
$d = $db->query("SELECT id FROM detalles_conductor WHERE usuario_id = 254")->fetch(PDO::FETCH_ASSOC);

echo "User 254: " . ($u ? "Found" : "Not Found") . "\n";
echo "Details 254: " . ($d ? "Found" : "Not Found") . "\n";
?>
