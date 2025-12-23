<?php
// Script de prueba para buscar conductores cercanos
header('Content-Type: application/json');

// Simular datos de búsqueda
$testData = [
    'latitud' => 6.2476,
    'longitud' => -75.5658,
    'tipo_vehiculo' => 'moto',
    'radio_km' => 5.0
];

// Hacer petición al endpoint
$ch = curl_init('http://localhost:8000/user/find_nearby_drivers.php');
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
