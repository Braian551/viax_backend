<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

// List tables
echo "Tables:\n";
$stmt = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- " . $row['table_name'] . "\n";
}

// Check columns of documentos_verificacion
echo "\nColumns of documentos_verificacion:\n";
$stmt = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'documentos_verificacion'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- " . $row['column_name'] . " (" . $row['data_type'] . ")\n";
}
?>
