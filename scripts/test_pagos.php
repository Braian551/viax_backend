<?php
require 'config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query('SELECT * FROM pagos_comision ORDER BY id DESC LIMIT 5');
$pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);

print_r($pagos);

$stmt2 = $db->query("
    SELECT COLUMN_NAME 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_NAME = 'pagos_comision'
");
print_r($stmt2->fetchAll(PDO::FETCH_COLUMN));
