<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

$conductor_id = 278;
$reason = "Documentos inconsistentes. Por favor verificar.";

$db->query("UPDATE detalles_conductor 
            SET estado_verificacion = 'rechazado', 
                estado_aprobacion = 'rechazado', 
                razon_rechazo = '$reason',
                aprobado = 0
            WHERE usuario_id = $conductor_id");

echo "Fixed user 278 status to 'rechazado'.\n";
?>
