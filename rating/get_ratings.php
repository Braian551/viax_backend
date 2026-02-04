<?php
/**
 * Endpoint: Obtener Calificaciones/Reseñas de un Usuario
 * 
 * GET /rating/get_ratings.php?usuario_id=123&tipo_usuario=conductor&page=1&limit=20
 * 
 * Retorna las calificaciones recibidas por un usuario (conductor o cliente)
 * incluyendo nombre del calificador, comentario y fecha.
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
    $usuarioId = $_GET['usuario_id'] ?? null;
    $tipoUsuario = $_GET['tipo_usuario'] ?? 'conductor';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    
    if (!$usuarioId) {
        throw new Exception('Se requiere usuario_id');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    $offset = ($page - 1) * $limit;
    
    // Obtener total de calificaciones
    $countStmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM calificaciones 
        WHERE usuario_calificado_id = ?
    ");
    $countStmt->execute([$usuarioId]);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Obtener calificaciones con información del calificador
    $stmt = $db->prepare("
        SELECT 
            c.id,
            c.solicitud_id,
            c.usuario_calificador_id,
            c.usuario_calificado_id,
            c.calificacion,
            c.comentarios,
            c.creado_en,
            u.nombre as nombre_calificador,
            u.apellido as apellido_calificador,
            u.foto_perfil as foto_calificador,
            s.direccion_recogida as origen,
            s.direccion_destino as destino
        FROM calificaciones c
        INNER JOIN usuarios u ON c.usuario_calificador_id = u.id
        LEFT JOIN solicitudes_servicio s ON c.solicitud_id = s.id
        WHERE c.usuario_calificado_id = ?
        ORDER BY c.creado_en DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$usuarioId, $limit, $offset]);
    $calificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener promedio y estadísticas
    $statsStmt = $db->prepare("
        SELECT 
            AVG(calificacion) as promedio,
            COUNT(*) as total,
            SUM(CASE WHEN calificacion = 5 THEN 1 ELSE 0 END) as cinco_estrellas,
            SUM(CASE WHEN calificacion = 4 THEN 1 ELSE 0 END) as cuatro_estrellas,
            SUM(CASE WHEN calificacion = 3 THEN 1 ELSE 0 END) as tres_estrellas,
            SUM(CASE WHEN calificacion = 2 THEN 1 ELSE 0 END) as dos_estrellas,
            SUM(CASE WHEN calificacion = 1 THEN 1 ELSE 0 END) as una_estrella
        FROM calificaciones 
        WHERE usuario_calificado_id = ?
    ");
    $statsStmt->execute([$usuarioId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Formatear calificaciones
    $calificacionesFormateadas = array_map(function($cal) {
        $nombreCompleto = trim(($cal['nombre_calificador'] ?? '') . ' ' . ($cal['apellido_calificador'] ?? ''));
        
        return [
            'id' => (int)$cal['id'],
            'solicitud_id' => (int)$cal['solicitud_id'],
            'calificacion' => (int)$cal['calificacion'],
            'comentario' => $cal['comentarios'] ?? '',
            'fecha_calificacion' => $cal['creado_en'],
            'nombre_calificador' => $nombreCompleto ?: 'Usuario',
            'foto_calificador' => $cal['foto_calificador'],
            'viaje' => [
                'origen' => $cal['origen'],
                'destino' => $cal['destino'],
            ],
        ];
    }, $calificaciones);
    
    echo json_encode([
        'success' => true,
        'calificaciones' => $calificacionesFormateadas,
        'estadisticas' => [
            'promedio' => $stats['promedio'] ? round((float)$stats['promedio'], 1) : 5.0,
            'total' => (int)$stats['total'],
            'distribucion' => [
                '5' => (int)$stats['cinco_estrellas'],
                '4' => (int)$stats['cuatro_estrellas'],
                '3' => (int)$stats['tres_estrellas'],
                '2' => (int)$stats['dos_estrellas'],
                '1' => (int)$stats['una_estrella'],
            ],
        ],
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_records' => (int)$total,
            'per_page' => $limit,
        ],
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
