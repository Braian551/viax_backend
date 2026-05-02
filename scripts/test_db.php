<?php
require 'config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT * FROM pagos_comision ORDER BY id DESC LIMIT 5");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
