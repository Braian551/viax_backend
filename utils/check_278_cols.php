<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT estado_aprobacion, razon_rechazo, estado_verificacion FROM detalles_conductor WHERE usuario_id = 278");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
?>
