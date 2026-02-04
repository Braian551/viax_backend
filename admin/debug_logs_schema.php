<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

echo "Columns of logs_auditoria:\n";
$stmt = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'logs_auditoria'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- " . $row['column_name'] . " (" . $row['data_type'] . ")\n";
}
?>
