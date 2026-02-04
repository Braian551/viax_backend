<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$db->query("UPDATE detalles_conductor SET estado_aprobacion = 'aprobado', razon_rechazo = NULL, aprobado = 1 WHERE usuario_id = 277");
$db->query("UPDATE usuarios SET es_verificado = 1 WHERE id = 277");
echo "Restored 277 to approved.\n";
?>
