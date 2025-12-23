<?php
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Crear solicitud de prueba cerca del conductor
$uuid = uniqid('SOL-', true);
$stmt = $db->prepare("
    INSERT INTO solicitudes_servicio (
        uuid_solicitud,
        cliente_id,
        latitud_recogida,
        longitud_recogida,
        direccion_recogida,
        latitud_destino,
        longitud_destino,
        direccion_destino,
        tipo_servicio,
        distancia_estimada,
        tiempo_estimado,
        estado,
        fecha_creacion,
        solicitado_en
    ) VALUES (
        ?,
        1,
        4.6100,
        -74.0820,
        'Calle 100 #15-20, Bogotá',
        4.6200,
        -74.0900,
        'Calle 50 #10-30, Bogotá',
        'transporte',
        5.2,
        15,
        'pendiente',
        NOW(),
        NOW()
    )
");

try {
    $stmt->execute([$uuid]);
    $solicitudId = $db->lastInsertId();
    echo "✅ Solicitud de prueba creada con ID: $solicitudId\n";
    echo "🆔 UUID: $uuid\n";
    echo "📍 Origen: Calle 100 #15-20, Bogotá\n";
    echo "📍 Destino: Calle 50 #10-30, Bogotá\n";
    echo "🚗 Tipo: Transporte\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
