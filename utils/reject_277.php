<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$db->query("UPDATE detalles_conductor SET estado_aprobacion = 'rechazado', razon_rechazo = 'Test Rejection' WHERE usuario_id = 277");
echo "Updated 277 to rejected.\n";
?>
