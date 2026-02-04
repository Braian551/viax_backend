<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

// Check if audit_logs exists
$stmt = $db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'audit_logs')");
$exists = $stmt->fetchColumn();

if ($exists == 't' || $exists == 1) {
    echo "audit_logs table EXISTS.\nColumns:\n";
    $stmt = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'audit_logs'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- " . $row['column_name'] . " (" . $row['data_type'] . ")\n";
    }
} else {
    echo "audit_logs table DOES NOT EXIST.\n";
}
?>
