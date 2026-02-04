<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

// Check columns of detalles_conductor
echo "Columns of detalles_conductor:\n";
$stmt = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'detalles_conductor'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- " . $row['column_name'] . " (" . $row['data_type'] . ")\n";
}
?>
