<?php
// Script de prueba para aceptar una solicitud
header('Content-Type: application/json');

// Datos de prueba (ajustar según tus IDs)
$testData = [
    'solicitud_id' => 25, // ID de una solicitud pendiente
    'conductor_id' => 7  // ID del conductor
];

// Hacer petición al endpoint
$ch = curl_init('http://localhost/ping_go/backend-deploy/conductor/accept_trip_request.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response:\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT);
?>
