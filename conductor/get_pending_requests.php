<?php
/**
 * Endpoint: Obtener Solicitudes Pendientes para Conductor
 * 
 * Filtra solicitudes por:
 * - Tipo de vehículo del conductor
 * - Empresa del conductor  
 * - Distancia al punto de recogida
 * - Estado pendiente y tiempo de solicitud
 */

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
    // Incluye empresa_id y tipo de vehículo para filtrar solicitudes
    $stmt = $db->prepare("
        SELECT u.id, u.empresa_id, dc.disponible, dc.latitud_actual, dc.longitud_actual, dc.vehiculo_tipo
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
    
    // Datos del conductor para filtrar
    $conductorVehiculoTipo = $conductor['vehiculo_tipo'];
    $conductorEmpresaId = $conductor['empresa_id'];
    $radioKm = 5.0; // Radio de búsqueda
    
    // Buscar solicitudes pendientes cercanas al conductor
    // Filtrar por tipo de vehículo y empresa del conductor
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
            s.tipo_vehiculo,
            s.empresa_id as solicitud_empresa_id,
            s.precio_estimado,
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
        AND (s.tipo_vehiculo IS NULL OR s.tipo_vehiculo = ?)
        AND (s.empresa_id IS NULL OR s.empresa_id = ?)
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
        $radioKm,
        $conductorVehiculoTipo,
        $conductorEmpresaId
    ]);
    
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear respuesta con datos adicionales
    $solicitudesFormateadas = array_map(function($s) {
        return [
            'id' => (int)$s['id'],
            'usuario_id' => (int)$s['usuario_id'],
            'latitud_origen' => (float)$s['latitud_origen'],
            'longitud_origen' => (float)$s['longitud_origen'],
            'direccion_origen' => $s['direccion_origen'],
            'latitud_destino' => (float)$s['latitud_destino'],
            'longitud_destino' => (float)$s['longitud_destino'],
            'direccion_destino' => $s['direccion_destino'],
            'tipo_servicio' => $s['tipo_servicio'],
            'tipo_vehiculo' => $s['tipo_vehiculo'] ?? 'moto',
            'empresa_id' => isset($s['solicitud_empresa_id']) ? (int)$s['solicitud_empresa_id'] : null,
            'distancia_km' => (float)$s['distancia_km'],
            'duracion_minutos' => (int)$s['duracion_minutos'],
            'precio_estimado' => isset($s['precio_estimado']) ? (float)$s['precio_estimado'] : null,
            'estado' => $s['estado'],
            'fecha_solicitud' => $s['fecha_solicitud'],
            'nombre_usuario' => $s['nombre_usuario'],
            'telefono_usuario' => $s['telefono_usuario'],
            'foto_usuario' => $s['foto_usuario'],
            'distancia_conductor_origen' => round((float)$s['distancia_conductor_origen'], 2),
        ];
    }, $solicitudes);
    
    echo json_encode([
        'success' => true,
        'total' => count($solicitudesFormateadas),
        'solicitudes' => $solicitudesFormateadas
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
