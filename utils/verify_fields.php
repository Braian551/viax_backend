<?php
ob_start();
$_GET['conductor_id'] = 277;
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/../conductor/get_profile.php';
$json = ob_get_clean();
$data = json_decode($json, true);
echo "Estado: " . $data['profile']['estado_aprobacion'] . "\n";
echo "Razon: " . $data['profile']['razon_rechazo'] . "\n";
?>
