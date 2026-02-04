<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'detalles_conductor'");
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($cols as $col) {
    echo $col . "\n";
}
?>
