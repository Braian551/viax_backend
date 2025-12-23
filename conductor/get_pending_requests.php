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
    // Obtener el ID del conductor
    $conductorId = $_GET['conductor_id'] ?? null;
    
    if (!$conductorId) {
        throw new Exception('ID del conductor requerido');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar que sea un conductor válido y disponible
    $stmt = $db->prepare("
        SELECT u.id, dc.disponible, dc.latitud_actual, dc.longitud_actual, dc.vehiculo_tipo as tipo_vehiculo
        FROM usuarios u
        INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE u.id = ? 
        AND u.tipo_usuario = 'conductor'
        AND dc.estado_verificacion = 'aprobado'
    ");
    $stmt->execute([$conductorId]);
    $conductor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conductor) {
        throw new Exception('Conductor no encontrado o no verificado');
    }
    
    if (!$conductor['disponible']) {
        echo json_encode([
            'success' => true,
            'message' => 'Conductor no disponible',
            'solicitudes' => []
        ]);
        exit;
    }
    
    // Buscar solicitudes pendientes cercanas al conductor
    $radioKm = 5.0; // Radio de búsqueda
    
    // Nota: Compatible con PostgreSQL - usa WHERE en lugar de HAVING
    // y nombres de columnas correctos según la estructura de la tabla
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.cliente_id as usuario_id,
            s.latitud_recogida as latitud_origen,
            s.longitud_recogida as longitud_origen,
            s.direccion_recogida as direccion_origen,
            s.latitud_destino,
            s.longitud_destino,
            s.direccion_destino,
            s.tipo_servicio,
            s.distancia_estimada as distancia_km,
            s.tiempo_estimado as duracion_minutos,
            s.estado,
            COALESCE(s.solicitado_en, s.fecha_creacion) as fecha_solicitud,
            u.nombre as nombre_usuario,
            u.telefono as telefono_usuario,
            u.foto_perfil as foto_usuario,
            (6371 * acos(
                cos(radians(?)) * cos(radians(s.latitud_recogida)) *
                cos(radians(s.longitud_recogida) - radians(?)) +
                sin(radians(?)) * sin(radians(s.latitud_recogida))
            )) AS distancia_conductor_origen
        FROM solicitudes_servicio s
        INNER JOIN usuarios u ON s.cliente_id = u.id
        WHERE s.estado = 'pendiente'
        AND COALESCE(s.solicitado_en, s.fecha_creacion) >= NOW() - INTERVAL '10 minutes'
        AND (6371 * acos(
            cos(radians(?)) * cos(radians(s.latitud_recogida)) *
            cos(radians(s.longitud_recogida) - radians(?)) +
            sin(radians(?)) * sin(radians(s.latitud_recogida))
        )) <= ?
        ORDER BY COALESCE(s.solicitado_en, s.fecha_creacion) DESC
        LIMIT 10
    ");
    
    $stmt->execute([
        $conductor['latitud_actual'],
        $conductor['longitud_actual'],
        $conductor['latitud_actual'],
        $conductor['latitud_actual'],
        $conductor['longitud_actual'],
        $conductor['latitud_actual'],
        $radioKm
    ]);
    
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total' => count($solicitudes),
        'solicitudes' => $solicitudes
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
