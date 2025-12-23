<?php
// Script de prueba para obtener solicitudes pendientes de un conductor
header('Content-Type: application/json');

// ID del conductor (cambia este valor según tu BD)
$conductorId = 7; // El conductor que creaste

// Hacer petición al endpoint
$ch = curl_init("http://localhost:8000/conductor/get_pending_requests.php?conductor_id=$conductorId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response:\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT);
?>
