<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $conductor_id = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $offset = ($page - 1) * $limit;

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Contar total de viajes
    $query_count = "SELECT COUNT(*) as total
                    FROM solicitudes_servicio s
                    INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
                    WHERE ac.conductor_id = :conductor_id
                    AND s.estado IN ('completada', 'entregado')";
    
    $stmt_count = $db->prepare($query_count);
    $stmt_count->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_count->execute();
    $total = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];

    // Obtener historial de viajes
    $query = "SELECT 
                s.id,
                s.tipo_servicio,
                s.estado,
                s.distancia_estimada as distancia_km,
                s.tiempo_estimado as duracion_estimada,
                s.solicitado_en as fecha_solicitud,
                s.completado_en as fecha_completado,
                s.direccion_recogida as origen,
                s.direccion_destino as destino,
                s.precio_estimado,
                s.precio_final,
                s.metodo_pago,
                s.pago_confirmado,
                u.nombre as cliente_nombre,
                u.apellido as cliente_apellido,
                c.calificacion,
                c.comentarios as comentario,
                COALESCE(t.monto_conductor, s.precio_final * 0.90, 0) as ganancia_viaje
              FROM solicitudes_servicio s
              INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
              INNER JOIN usuarios u ON s.cliente_id = u.id
              LEFT JOIN calificaciones c ON s.id = c.solicitud_id AND c.usuario_calificado_id = :conductor_id2
              LEFT JOIN transacciones t ON s.id = t.solicitud_id
              WHERE ac.conductor_id = :conductor_id
              AND s.estado IN ('completada', 'entregado')
              ORDER BY s.completado_en DESC
              LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt->bindParam(':conductor_id2', $conductor_id, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Asegurar que los valores numéricos sean del tipo correcto
    $viajes = array_map(function($viaje) {
        return [
            'id' => (int)$viaje['id'],
            'tipo_servicio' => $viaje['tipo_servicio'],
            'estado' => $viaje['estado'],
            'distancia_km' => $viaje['distancia_km'] ? (float)$viaje['distancia_km'] : null,
            'duracion_estimada' => $viaje['duracion_estimada'] ? (int)$viaje['duracion_estimada'] : null,
            'fecha_solicitud' => $viaje['fecha_solicitud'],
            'fecha_completado' => $viaje['fecha_completado'],
            'origen' => $viaje['origen'],
            'destino' => $viaje['destino'],
            'cliente_nombre' => $viaje['cliente_nombre'],
            'cliente_apellido' => $viaje['cliente_apellido'],
            'calificacion' => $viaje['calificacion'] ? (int)$viaje['calificacion'] : null,
            'comentario' => $viaje['comentario'],
            'precio_estimado' => (float)($viaje['precio_estimado'] ?? 0),
            'precio_final' => (float)($viaje['precio_final'] ?? 0),
            'metodo_pago' => $viaje['metodo_pago'] ?? 'efectivo',
            'pago_confirmado' => (bool)$viaje['pago_confirmado'],
            'ganancia_viaje' => (float)($viaje['ganancia_viaje'] ?? 0)
        ];
    }, $viajes);

    echo json_encode([
        'success' => true,
        'viajes' => $viajes,
        'pagination' => [
            'page' => (int)$page,
            'limit' => (int)$limit,
            'total' => (int)$total,
            'total_pages' => (int)ceil($total / $limit)
        ],
        'message' => 'Historial obtenido exitosamente'
    ], JSON_NUMERIC_CHECK);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'viajes' => []
    ]);
}
?>
