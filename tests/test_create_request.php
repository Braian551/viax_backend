<?php
// Script de prueba para crear una solicitud de viaje
header('Content-Type: application/json');

// Simular datos de solicitud
$testData = [
    'usuario_id' => 1, // Asegúrate de que este usuario exista en tu BD
    'latitud_origen' => 6.2476,
    'longitud_origen' => -75.5658,
    'direccion_origen' => 'Carrera 18B #62-191, Llanaditas, Comuna 8 - Villa Hermosa',
    'latitud_destino' => 6.2001,
    'longitud_destino' => -75.5791,
    'direccion_destino' => 'La Estrella, Antioquia',
    'tipo_servicio' => 'viaje',
    'tipo_vehiculo' => 'moto',
    'distancia_km' => 5.2,
    'duracion_minutos' => 15,
    'precio_estimado' => 12000.00
];

// Hacer petición al endpoint
$ch = curl_init('http://localhost:8000/user/create_trip_request.php');
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
