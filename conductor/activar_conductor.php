<?php
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$conductorId = 7;

// Actualizar disponibilidad del conductor
$stmt = $db->prepare("
    UPDATE detalles_conductor 
    SET disponible = 1,
        latitud_actual = 4.6097,
        longitud_actual = -74.0817,
        ultima_actualizacion = NOW()
    WHERE usuario_id = ?
");
$stmt->execute([$conductorId]);

echo "✅ Conductor ID $conductorId marcado como disponible\n";

// Verificar
$stmt = $db->prepare("
    SELECT disponible, latitud_actual, longitud_actual 
    FROM detalles_conductor 
    WHERE usuario_id = ?
");
$stmt->execute([$conductorId]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Estado actual:\n";
echo "- Disponible: " . ($result['disponible'] ? 'Sí' : 'No') . "\n";
echo "- Lat: " . $result['latitud_actual'] . "\n";
echo "- Lng: " . $result['longitud_actual'] . "\n";
