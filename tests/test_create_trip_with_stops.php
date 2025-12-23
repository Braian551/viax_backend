<?php
require_once __DIR__ . '/../config/database.php';

// URL del endpoint
$url = 'http://localhost/viax/backend/user/create_trip_request.php';

// Datos de prueba
$data = [
    'usuario_id' => 2, // Usuario cliente válido
    'latitud_origen' => 4.6097,
    'longitud_origen' => -74.0817,
    'direccion_origen' => 'Plaza de Bolívar, Bogotá',
    'latitud_destino' => 4.7110,
    'longitud_destino' => -74.0721,
    'direccion_destino' => 'Centro Comercial Andino, Bogotá',
    'tipo_servicio' => 'viaje',
    'tipo_vehiculo' => 'auto',
    'distancia_km' => 12.5,
    'duracion_minutos' => 35,
    'precio_estimado' => 25000,
    'paradas' => [
        [
            'latitud' => 4.6584,
            'longitud' => -74.0939,
            'direccion' => 'Parque Simón Bolívar'
        ],
        [
            'latitud' => 4.6243,
            'longitud' => -74.1678,
            'direccion' => 'Aeropuerto El Dorado'
        ]
    ]
];

// Inicializar cURL
$ch = curl_init($url);

// Configurar opciones
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

// Ejecutar solicitud
$response = curl_exec($ch);

// Verificar errores
if (curl_errno($ch)) {
    echo 'Error cURL: ' . curl_error($ch);
} else {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "Código HTTP: $httpCode\n";
    echo "Respuesta:\n$response\n";
    
    // Verificar si se guardaron las paradas en la base de datos
    $responseData = json_decode($response, true);
    if (isset($responseData['success']) && $responseData['success']) {
        $solicitudId = $responseData['solicitud_id'];
        echo "\nVerificando base de datos para solicitud ID: $solicitudId...\n";
        
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT * FROM paradas_solicitud WHERE solicitud_id = :id ORDER BY orden ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $solicitudId);
        $stmt->execute();
        
        $paradas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Paradas encontradas: " . count($paradas) . "\n";
        foreach ($paradas as $parada) {
            echo "- Orden {$parada['orden']}: {$parada['direccion']} ({$parada['latitud']}, {$parada['longitud']})\n";
        }
    }
}

// Cerrar cURL
curl_close($ch);
?>
