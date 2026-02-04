<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

echo "Columns of documentos_conductor_historial:\n";
$stmt = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'documentos_conductor_historial'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- " . $row['column_name'] . " (" . $row['data_type'] . ")\n";
}
?>
