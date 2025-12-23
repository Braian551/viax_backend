<?php
// Script de prueba para get_solicitudes_pendientes.php

$url = 'http://localhost/pingo/backend/conductor/get_solicitudes_pendientes.php';

$data = [
    'conductor_id' => 7,
    'latitud_actual' => 4.6097,
    'longitud_actual' => -74.0817,
    'radio_km' => 5.0
];

$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($data),
        'ignore_errors' => true
    ]
];

$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

echo "=== PRUEBA DE ENDPOINT ===\n";
echo "URL: $url\n";
echo "Request: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
echo "\n=== RESPONSE ===\n";

if ($result === false) {
    echo "ERROR: No se pudo conectar al endpoint\n";
    var_dump(error_get_last());
} else {
    echo "Status Code: " . explode(' ', $http_response_header[0])[1] . "\n";
    echo "Response:\n";
    
    $decoded = json_decode($result, true);
    if ($decoded) {
        echo json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "Raw response:\n";
        echo $result . "\n";
    }
}
