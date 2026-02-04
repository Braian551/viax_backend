<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT prosrc FROM pg_proc WHERE proname = 'aprobar_vinculacion_conductor'");
$src = $stmt->fetchColumn();
if ($src) {
    echo "=== SQL FUNCTION: aprobar_vinculacion_conductor ===\n";
    echo $src;
} else {
    echo "Function not found!\n";
}
?>
