<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

function checkCols($db, $table) {
    echo "\n--- Columns for $table ---\n";
    $stmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = :table");
    $stmt->execute([':table' => $table]);
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
}

checkCols($db, 'detalles_conductor');
checkCols($db, 'solicitudes_vinculacion_conductor');
?>
