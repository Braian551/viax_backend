<?php
/**
 * Audit Logs API
 * Obtiene logs de auditoría del sistema
 */

require_once __DIR__ . '/../middleware/SessionAccess.php';

$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $db = (new Database())->getConnection();
    SessionAccess::requireAdmin($db);
    if (Request::method() !== 'GET') {
        header('Allow: GET');
        sendJsonResponse(false, 'Operación no disponible.', [], 405);
    }
    $input = $_GET;

    // Parámetros de paginación y filtros
    $page = isset($input['page']) ? max(1, min(10000, (int)$input['page'])) : 1;
    $perPage = isset($input['per_page']) ? max(1, min(100, (int)$input['per_page'])) : 50;
    $offset = ($page - 1) * $perPage;
    
    $accion = isset($input['accion']) ? $input['accion'] : null;
    $usuarioId = isset($input['usuario_id']) ? $input['usuario_id'] : null;
    $fechaDesde = isset($input['fecha_desde']) ? $input['fecha_desde'] : null;
    $fechaHasta = isset($input['fecha_hasta']) ? $input['fecha_hasta'] : null;

    // Construir query con filtros
    $whereConditions = [];
    $params = [];

    if ($accion) {
        $whereConditions[] = "l.accion = ?";
        $params[] = $accion;
    }

    if ($usuarioId) {
        $whereConditions[] = "l.usuario_id = ?";
        $params[] = $usuarioId;
    }

    if ($fechaDesde) {
        $whereConditions[] = "DATE(l.fecha_creacion) >= ?";
        $params[] = $fechaDesde;
    }

    if ($fechaHasta) {
        $whereConditions[] = "DATE(l.fecha_creacion) <= ?";
        $params[] = $fechaHasta;
    }

    $whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);

    // Contar total
    $countQuery = "SELECT COUNT(*) as total FROM logs_auditoria l $whereClause";
    $stmtCount = $db->prepare($countQuery);
    $stmtCount->execute($params);
    $total = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

    // Obtener logs
    $query = "SELECT 
        l.id,
        l.usuario_id,
        l.accion,
        l.entidad,
        l.entidad_id,
        l.descripcion,
        l.ip_address,
        l.user_agent,
        l.fecha_creacion,
        u.nombre,
        u.apellido,
        u.email,
        u.tipo_usuario
    FROM logs_auditoria l
    LEFT JOIN usuarios u ON l.usuario_id = u.id
    $whereClause
    ORDER BY l.fecha_creacion DESC
    LIMIT ? OFFSET ?";
    
    $params[] = $perPage;
    $params[] = $offset;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener estadísticas de acciones
    $statsQuery = "SELECT 
        accion,
        COUNT(*) as cantidad
    FROM logs_auditoria
    WHERE fecha_creacion >= NOW() - INTERVAL '30 days'
    GROUP BY accion
    ORDER BY cantidad DESC
    LIMIT 10";
    
    $stmtStats = $db->query($statsQuery);
    $stats = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

    sendJsonResponse(true, 'Logs obtenidos exitosamente', [
        'logs' => $logs,
        'estadisticas' => $stats,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ]
    ]);

} catch (Exception $e) {
    error_log("Error en audit_logs: " . $e->getMessage());
    sendJsonResponse(false, 'No se pudo cargar la auditoría.', [], 500);
}
