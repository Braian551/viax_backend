<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    $solicitudId = $_GET['solicitud_id'] ?? null;
    
    if (!$solicitudId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'solicitud_id es requerido']);
        exit();
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Query simplificada primero para verificar que la solicitud existe
    $stmtCheck = $db->prepare("SELECT id, estado FROM solicitudes_servicio WHERE id = ?");
    $stmtCheck->execute([$solicitudId]);
    $check = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$check) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit();
    }
    
    // Obtener información completa de la solicitud con datos del conductor asignado
    $stmt = $db->prepare("
        SELECT 
            s.*,
            ac.conductor_id,
            ac.estado as estado_asignacion,
            ac.asignado_en as fecha_asignacion,
            u.nombre as conductor_nombre,
            u.apellido as conductor_apellido,
            u.telefono as conductor_telefono,
            u.foto_perfil as conductor_foto,
            dc.vehiculo_tipo,
            dc.vehiculo_marca,
            dc.vehiculo_modelo,
            dc.vehiculo_placa,
            dc.vehiculo_color,
            dc.calificacion_promedio as conductor_calificacion,
            dc.latitud_actual as conductor_latitud,
            dc.longitud_actual as conductor_longitud
        FROM solicitudes_servicio s
        LEFT JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id AND ac.estado IN ('asignado', 'llegado')
        LEFT JOIN usuarios u ON ac.conductor_id = u.id
        LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE s.id = ?
    ");
    
    $stmt->execute([$solicitudId]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calcular distancia si hay conductor con ubicación
    $distancia_conductor_km = null;
    $eta_minutos = null;
    
    if ($trip['conductor_id'] && $trip['conductor_latitud'] && $trip['conductor_longitud']) {
        // Calcular distancia usando fórmula Haversine
        $lat1 = deg2rad($trip['latitud_recogida']);
        $lon1 = deg2rad($trip['longitud_recogida']);
        $lat2 = deg2rad($trip['conductor_latitud']);
        $lon2 = deg2rad($trip['conductor_longitud']);
        
        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;
        
        $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlon/2) * sin($dlon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distancia_conductor_km = 6371 * $c;
        
        // Estimación simple: 30 km/h promedio en ciudad
        $eta_minutos = round(($distancia_conductor_km / 30) * 60);
    }
    
    echo json_encode([
        'success' => true,
        'trip' => [
            'id' => (int)$trip['id'],
            'uuid' => $trip['uuid_solicitud'],
            'estado' => $trip['estado'],
            'tipo_servicio' => $trip['tipo_servicio'],
            'origen' => [
                'latitud' => (float)$trip['latitud_recogida'],
                'longitud' => (float)$trip['longitud_recogida'],
                'direccion' => $trip['direccion_recogida']
            ],
            'destino' => [
                'latitud' => (float)$trip['latitud_destino'],
                'longitud' => (float)$trip['longitud_destino'],
                'direccion' => $trip['direccion_destino']
            ],
            'distancia_km' => (float)($trip['distancia_estimada'] ?? 0),
            'tiempo_estimado_min' => (int)($trip['tiempo_estimado'] ?? 0),
            'fecha_creacion' => $trip['fecha_creacion'],
            'conductor' => $trip['conductor_id'] ? [
                'id' => (int)$trip['conductor_id'],
                'nombre' => trim($trip['conductor_nombre'] . ' ' . $trip['conductor_apellido']),
                'telefono' => $trip['conductor_telefono'],
                'foto' => $trip['conductor_foto'],
                'calificacion' => (float)($trip['conductor_calificacion'] ?? 0),
                'vehiculo' => [
                    'tipo' => $trip['vehiculo_tipo'],
                    'marca' => $trip['vehiculo_marca'],
                    'modelo' => $trip['vehiculo_modelo'],
                    'placa' => $trip['vehiculo_placa'],
                    'color' => $trip['vehiculo_color']
                ],
                'ubicacion' => [
                    'latitud' => (float)$trip['conductor_latitud'],
                    'longitud' => (float)$trip['conductor_longitud']
                ],
                'distancia_km' => $distancia_conductor_km ? round($distancia_conductor_km, 2) : null,
                'eta_minutos' => $eta_minutos
            ] : null
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("get_trip_status.php PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de base de datos'
    ]);
} catch (Exception $e) {
    error_log("get_trip_status.php Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
