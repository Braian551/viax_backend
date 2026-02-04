<?php
/**
 * get_tickets.php
 * Obtiene los tickets de soporte de un usuario
 * 
 * GET params:
 * - usuario_id: (required) ID del usuario
 * - estado: (optional) Filtrar por estado
 * - page: (optional) Número de página (default: 1)
 * - limit: (optional) Cantidad por página (default: 20)
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
    
    $usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;
    $estado = isset($_GET['estado']) ? trim($_GET['estado']) : null;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    
    if ($usuario_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'usuario_id es requerido']);
        exit();
    }
    
    $offset = ($page - 1) * $limit;
    
    // Construir query
    $whereClause = "WHERE t.usuario_id = :usuario_id";
    $params = [':usuario_id' => $usuario_id];
    
    if ($estado) {
        $whereClause .= " AND t.estado = :estado";
        $params[':estado'] = $estado;
    }
    
    // Contar total
    $countQuery = "SELECT COUNT(*) as total FROM tickets_soporte t $whereClause";
    $countStmt = $conn->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $limit);
    
    // Obtener tickets
    $query = "
        SELECT 
            t.id,
            t.numero_ticket,
            t.asunto,
            t.estado,
            t.prioridad,
            t.created_at,
            t.updated_at,
            c.codigo as categoria_codigo,
            c.nombre as categoria_nombre,
            c.icono as categoria_icono,
            c.color as categoria_color,
            (SELECT COUNT(*) FROM mensajes_ticket m WHERE m.ticket_id = t.id AND m.es_agente = TRUE AND m.leido = FALSE) as mensajes_no_leidos
        FROM tickets_soporte t
        INNER JOIN categorias_soporte c ON t.categoria_id = c.id
        $whereClause
        ORDER BY t.updated_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear datos
    foreach ($tickets as &$ticket) {
        $ticket['mensajes_no_leidos'] = (int) $ticket['mensajes_no_leidos'];
    }
    
    echo json_encode([
        'success' => true,
        'tickets' => $tickets,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_records' => (int) $totalRecords,
            'per_page' => $limit,
            'has_more' => $page < $totalPages
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
