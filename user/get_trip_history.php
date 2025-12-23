<?php
/**
 * get_trip_history.php
 * Obtiene el historial de viajes de un usuario
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener parámetros
    $usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $estado = isset($_GET['estado']) ? $_GET['estado'] : null;
    
    if ($usuario_id <= 0) {
        echo json_encode([
            'success' => false,
            'error' => 'usuario_id es requerido'
        ]);
        exit;
    }
    
    $offset = ($page - 1) * $limit;
    
    // Construir query base
    $whereClause = "WHERE ss.usuario_id = :usuario_id";
    $params = [':usuario_id' => $usuario_id];
    
    // Filtro por estado
    if ($estado && $estado !== 'all') {
        if ($estado === 'completada') {
            $whereClause .= " AND ss.estado IN ('completada', 'entregado')";
        } else {
            $whereClause .= " AND ss.estado = :estado";
            $params[':estado'] = $estado;
        }
    }
    
    // Obtener total de registros
    $countQuery = "SELECT COUNT(*) as total FROM solicitudes_servicio ss $whereClause";
    $countStmt = $conn->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $limit);
    
    // Query principal con JOIN a conductores y transacciones
    $query = "
        SELECT 
            ss.id,
            ss.tipo_servicio,
            ss.estado,
            ss.origen,
            ss.destino,
            ss.distancia_km,
            ss.duracion_estimada,
            COALESCE(ss.precio_estimado, 0) as precio_estimado,
            COALESCE(ss.precio_final, ss.precio_estimado, 0) as precio_final,
            COALESCE(ss.metodo_pago, 'efectivo') as metodo_pago,
            COALESCE(ss.pago_confirmado, false) as pago_confirmado,
            ss.fecha_solicitud,
            ss.fecha_completado,
            u.nombre as conductor_nombre,
            u.apellido as conductor_apellido,
            dc.calificacion as calificacion_conductor
        FROM solicitudes_servicio ss
        LEFT JOIN detalles_conductor dc ON ss.conductor_id = dc.conductor_id
        LEFT JOIN usuarios u ON dc.conductor_id = u.id
        $whereClause
        ORDER BY ss.fecha_solicitud DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear datos
    $viajesFormateados = array_map(function($viaje) {
        return [
            'id' => (int)$viaje['id'],
            'tipo_servicio' => $viaje['tipo_servicio'] ?? 'transporte',
            'estado' => $viaje['estado'],
            'origen' => $viaje['origen'] ?? '',
            'destino' => $viaje['destino'] ?? '',
            'distancia_km' => $viaje['distancia_km'] ? (float)$viaje['distancia_km'] : null,
            'duracion_estimada' => $viaje['duracion_estimada'] ? (int)$viaje['duracion_estimada'] : null,
            'precio_estimado' => (float)$viaje['precio_estimado'],
            'precio_final' => (float)$viaje['precio_final'],
            'metodo_pago' => $viaje['metodo_pago'],
            'pago_confirmado' => (bool)$viaje['pago_confirmado'],
            'fecha_solicitud' => $viaje['fecha_solicitud'],
            'fecha_completado' => $viaje['fecha_completado'],
            'conductor_nombre' => $viaje['conductor_nombre'],
            'conductor_apellido' => $viaje['conductor_apellido'],
            'calificacion_conductor' => $viaje['calificacion_conductor'] ? (float)$viaje['calificacion_conductor'] : null,
        ];
    }, $viajes);
    
    echo json_encode([
        'success' => true,
        'viajes' => $viajesFormateados,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_records' => (int)$totalRecords,
            'per_page' => $limit
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>
