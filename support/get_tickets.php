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
require_once __DIR__ . '/_support_auth.php';

try {
    $database = new Database();
    $conn = $database->getConnection();

    $usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;
    $agente_id = isset($_GET['agente_id']) ? intval($_GET['agente_id']) : 0;
    $estado = isset($_GET['estado']) ? trim($_GET['estado']) : null;
    $prioridad = isset($_GET['prioridad']) ? trim($_GET['prioridad']) : null;
    $asignado_a = isset($_GET['asignado_a']) ? trim((string) $_GET['asignado_a']) : null;
    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : null;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;

    $actorId = $agente_id > 0 ? $agente_id : $usuario_id;
    $actor = supportGetActor($conn, $actorId);
    if (!$actor) {
        supportJsonError('Actor no encontrado', 404);
    }

    $isAgent = (bool) $actor['es_agente_soporte'];
    if (!$isAgent && $usuario_id <= 0) {
        supportJsonError('usuario_id es requerido', 400);
    }
    
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if ($isAgent) {
        if ($usuario_id > 0) {
            $conditions[] = 't.usuario_id = :usuario_id';
            $params[':usuario_id'] = $usuario_id;
        }
    } else {
        $conditions[] = 't.usuario_id = :usuario_id';
        $params[':usuario_id'] = $usuario_id;
    }

    if ($estado) {
        $conditions[] = 't.estado = :estado';
        $params[':estado'] = $estado;
    }

    if ($prioridad) {
        $conditions[] = 't.prioridad = :prioridad';
        $params[':prioridad'] = $prioridad;
    }

    if ($isAgent && $asignado_a !== null && $asignado_a !== '') {
        if ($asignado_a === 'sin_asignar') {
            $conditions[] = 't.agente_id IS NULL';
        } elseif ($asignado_a === 'yo') {
            $conditions[] = 't.agente_id = :agente_actual';
            $params[':agente_actual'] = $actorId;
        } else {
            $conditions[] = 't.agente_id = :agente_filtro';
            $params[':agente_filtro'] = (int) $asignado_a;
        }
    }

    if ($search) {
        $conditions[] = '(t.numero_ticket ILIKE :search OR t.asunto ILIKE :search OR u.nombre ILIKE :search OR u.apellido ILIKE :search OR u.email ILIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $whereClause = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
    
    // Contar total
    $countQuery = "\n        SELECT COUNT(*) as total\n        FROM tickets_soporte t\n        INNER JOIN usuarios u ON t.usuario_id = u.id\n        $whereClause\n    ";
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
            t.agente_id,
            t.created_at,
            t.updated_at,
            c.codigo as categoria_codigo,
            c.nombre as categoria_nombre,
            c.icono as categoria_icono,
            c.color as categoria_color,
            u.nombre as usuario_nombre,
            u.apellido as usuario_apellido,
            u.email as usuario_email,
            a.nombre as agente_nombre,
            a.apellido as agente_apellido,
            (SELECT COUNT(*) FROM mensajes_ticket m WHERE m.ticket_id = t.id AND m.es_agente = :unread_from_agent AND m.leido = FALSE) as mensajes_no_leidos
        FROM tickets_soporte t
        INNER JOIN categorias_soporte c ON t.categoria_id = c.id
        INNER JOIN usuarios u ON t.usuario_id = u.id
        LEFT JOIN usuarios a ON t.agente_id = a.id
        $whereClause
        ORDER BY t.updated_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':unread_from_agent', $isAgent ? false : true, PDO::PARAM_BOOL);
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
        $ticket['es_agente_view'] = $isAgent;
    }

    $agentes = [];
    if ($isAgent) {
        $agentsStmt = $conn->prepare("\n            SELECT id, nombre, apellido, email, tipo_usuario\n            FROM usuarios\n            WHERE tipo_usuario IN ('administrador', 'soporte_tecnico')\n              AND es_activo = 1\n            ORDER BY nombre ASC, apellido ASC\n        ");
        $agentsStmt->execute();
        $agentes = $agentsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'tickets' => $tickets,
        'actor' => [
            'id' => (int) $actor['id'],
            'tipo_usuario' => $actor['tipo_usuario'],
            'es_agente_soporte' => $isAgent,
        ],
        'agentes' => $agentes,
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
