<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    if (!isset($data['latitud']) || !isset($data['longitud']) || !isset($data['tipo_vehiculo'])) {
        throw new Exception('Datos requeridos: latitud, longitud, tipo_vehiculo');
    }
    
    $latitud = $data['latitud'];
    $longitud = $data['longitud'];
    $tipoVehiculo = $data['tipo_vehiculo'];
    $radioKm = $data['radio_km'] ?? 5.0;
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Mapear tipo de vehículo de la app a la BD
    $vehiculoTipoMap = [
        'moto' => 'moto',
        'auto' => 'auto',
        'motocarro' => 'motocarro'
    ];
    $vehiculoTipoBD = $vehiculoTipoMap[$tipoVehiculo] ?? 'moto';
    
    // Buscar conductores cercanos disponibles usando la fórmula de Haversine
    // Nota: Compatible con PostgreSQL - usa WHERE en lugar de HAVING
    $stmt = $db->prepare("
        SELECT 
            u.id,
            u.nombre,
            u.apellido,
            u.telefono,
            u.foto_perfil,
            dc.vehiculo_tipo,
            dc.vehiculo_marca,
            dc.vehiculo_modelo,
            dc.vehiculo_placa,
            dc.vehiculo_color,
            dc.calificacion_promedio,
            dc.total_viajes,
            dc.latitud_actual,
            dc.longitud_actual,
            (6371 * acos(
                cos(radians(?)) * cos(radians(dc.latitud_actual)) *
                cos(radians(dc.longitud_actual) - radians(?)) +
                sin(radians(?)) * sin(radians(dc.latitud_actual))
            )) AS distancia_km
        FROM usuarios u
        INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE u.tipo_usuario = 'conductor'
        AND u.es_activo = 1
        AND dc.disponible = 1
        AND dc.estado_verificacion = 'aprobado'
        AND dc.vehiculo_tipo = ?
        AND dc.latitud_actual IS NOT NULL
        AND dc.longitud_actual IS NOT NULL
        AND (6371 * acos(
            cos(radians(?)) * cos(radians(dc.latitud_actual)) *
            cos(radians(dc.longitud_actual) - radians(?)) +
            sin(radians(?)) * sin(radians(dc.latitud_actual))
        )) <= ?
        ORDER BY distancia_km ASC
        LIMIT 20
    ");
    
    $stmt->execute([
        $latitud,
        $longitud,
        $latitud,
        $vehiculoTipoBD,
        $latitud,
        $longitud,
        $latitud,
        $radioKm
    ]);
    
    $conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total' => count($conductores),
        'conductores' => $conductores
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
