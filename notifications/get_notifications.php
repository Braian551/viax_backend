<?php
/**
 * get_notifications.php
 * Obtiene las notificaciones de un usuario con paginación y filtros
 * 
 * Parámetros GET:
 * - usuario_id: (requerido) ID del usuario
 * - page: Número de página (default: 1)
 * - limit: Cantidad por página (default: 20, max: 50)
 * - solo_no_leidas: Filtrar solo no leídas (default: false)
 * - tipo: Filtrar por tipo de notificación
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener parámetros
    $usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $solo_no_leidas = isset($_GET['solo_no_leidas']) && $_GET['solo_no_leidas'] === 'true';
    $tipo = isset($_GET['tipo']) ? trim($_GET['tipo']) : null;
    
    if ($usuario_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'usuario_id es requerido'
        ]);
        exit;
    }
    
    $offset = ($page - 1) * $limit;
    
    // Construir query
    $whereClause = "WHERE n.usuario_id = :usuario_id AND n.eliminada = FALSE";
    $params = [':usuario_id' => $usuario_id];
    
    if ($solo_no_leidas) {
        $whereClause .= " AND n.leida = FALSE";
    }
    
    if ($tipo) {
        $whereClause .= " AND t.codigo = :tipo";
        $params[':tipo'] = $tipo;
    }
    
    // Contar total
    $countQuery = "
        SELECT COUNT(*) as total 
        FROM notificaciones_usuario n
        INNER JOIN tipos_notificacion t ON n.tipo_id = t.id
        $whereClause
    ";
    $countStmt = $conn->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $limit);
    
    // Obtener notificaciones usando la vista
    $query = "
        SELECT 
            n.id,
            n.titulo,
            n.mensaje,
            n.leida,
            n.leida_en,
            n.referencia_tipo,
            n.referencia_id,
            n.data,
            n.created_at,
            t.codigo as tipo,
            t.nombre as tipo_nombre,
            t.icono as tipo_icono,
            t.color as tipo_color
        FROM notificaciones_usuario n
        INNER JOIN tipos_notificacion t ON n.tipo_id = t.id
        $whereClause
        ORDER BY n.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear datos
    foreach ($notificaciones as &$notif) {
        $notif['leida'] = (bool) $notif['leida'];
        $notif['data'] = json_decode($notif['data'], true) ?? [];
    }
    
    // Obtener conteo de no leídas
    $countUnreadQuery = "SELECT contar_notificaciones_no_leidas(:usuario_id) as count";
    $countUnreadStmt = $conn->prepare($countUnreadQuery);
    $countUnreadStmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $countUnreadStmt->execute();
    $unreadCount = $countUnreadStmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo json_encode([
        'success' => true,
        'notificaciones' => $notificaciones,
        'no_leidas' => (int) $unreadCount,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_records' => (int) $totalRecords,
            'per_page' => $limit,
            'has_more' => $page < $totalPages
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos',
        'details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
