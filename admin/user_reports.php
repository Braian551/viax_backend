<?php
/**
 * API: Gestión de reportes de usuarios (Admin/Soporte)
 * Endpoint: GET/POST admin/user_reports.php
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/config.php';

function isSupportAgentRole(?string $role): bool
{
    return in_array((string)$role, ['administrador', 'admin', 'soporte_tecnico'], true);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $input = $method === 'GET' ? $_GET : getJsonInput();
    $actorId = isset($input['actor_id']) ? (int)$input['actor_id'] : 0;

    if ($actorId <= 0) {
        sendJsonResponse(false, 'actor_id es requerido', [], 400);
    }

    $stmtActor = $db->prepare('SELECT id, tipo_usuario, nombre, apellido FROM usuarios WHERE id = :id LIMIT 1');
    $stmtActor->execute([':id' => $actorId]);
    $actor = $stmtActor->fetch(PDO::FETCH_ASSOC);

    if (!$actor || !isSupportAgentRole($actor['tipo_usuario'] ?? null)) {
        sendJsonResponse(false, 'Acceso denegado. Solo admin/soporte.', [], 403);
    }

    if ($method === 'GET') {
        $estado = trim((string)($input['estado'] ?? ''));
        $prioridad = trim((string)($input['prioridad'] ?? ''));
        $search = trim((string)($input['search'] ?? ''));
        $limit = max(1, min(100, (int)($input['limit'] ?? 50)));

        $where = [];
        $params = [];

        if ($estado !== '') {
            $where[] = 'r.estado = :estado';
            $params[':estado'] = $estado;
        }
        if ($prioridad !== '') {
            $where[] = 'r.prioridad = :prioridad';
            $params[':prioridad'] = $prioridad;
        }
        if ($search !== '') {
            $where[] = '(
                reporter.nombre ILIKE :search OR
                reporter.apellido ILIKE :search OR
                reported.nombre ILIKE :search OR
                reported.apellido ILIKE :search OR
                r.motivo ILIKE :search OR
                r.descripcion ILIKE :search
            )';
            $params[':search'] = '%' . $search . '%';
        }

        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $sql = "
            SELECT
                r.id,
                r.reporter_user_id,
                r.reported_user_id,
                r.solicitud_id,
                r.motivo,
                r.descripcion,
                r.estado,
                r.prioridad,
                r.reviewed_by,
                r.reviewed_at,
                r.resolution_note,
                r.created_at,
                r.updated_at,
                reporter.nombre AS reporter_nombre,
                reporter.apellido AS reporter_apellido,
                reported.nombre AS reported_nombre,
                reported.apellido AS reported_apellido,
                reviewer.nombre AS reviewer_nombre,
                reviewer.apellido AS reviewer_apellido
            FROM reportes_usuarios r
            INNER JOIN usuarios reporter ON reporter.id = r.reporter_user_id
            INNER JOIN usuarios reported ON reported.id = r.reported_user_id
            LEFT JOIN usuarios reviewer ON reviewer.id = r.reviewed_by
            $whereSql
            ORDER BY
                CASE r.estado
                    WHEN 'pendiente' THEN 0
                    WHEN 'en_revision' THEN 1
                    WHEN 'resuelto' THEN 2
                    WHEN 'descartado' THEN 3
                    ELSE 9
                END,
                CASE r.prioridad
                    WHEN 'urgente' THEN 0
                    WHEN 'alta' THEN 1
                    WHEN 'media' THEN 2
                    WHEN 'baja' THEN 3
                    ELSE 9
                END,
                r.created_at DESC
            LIMIT :limit
        ";

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $summarySql = "
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE estado = 'pendiente') AS pendientes,
                COUNT(*) FILTER (WHERE estado = 'en_revision') AS en_revision,
                COUNT(*) FILTER (WHERE estado = 'resuelto') AS resueltos,
                COUNT(*) FILTER (WHERE estado = 'descartado') AS descartados
            FROM reportes_usuarios
        ";
        $summary = $db->query($summarySql)->fetch(PDO::FETCH_ASSOC) ?: [];

        sendJsonResponse(true, 'Reportes obtenidos', [
            'reportes' => $rows,
            'resumen' => $summary,
        ]);
    }

    if ($method === 'POST') {
        $reportId = isset($input['report_id']) ? (int)$input['report_id'] : 0;
        $action = trim((string)($input['action'] ?? ''));
        $resolutionNote = trim((string)($input['resolution_note'] ?? ''));

        if ($reportId <= 0 || $action === '') {
            sendJsonResponse(false, 'report_id y action son requeridos', [], 400);
        }

        $allowedActions = [
            'start_review' => 'en_revision',
            'resolve' => 'resuelto',
            'dismiss' => 'descartado',
            'reopen' => 'pendiente',
        ];
        if (!isset($allowedActions[$action])) {
            sendJsonResponse(false, 'Acción no válida', [], 400);
        }

        $newStatus = $allowedActions[$action];

        $stmt = $db->prepare(
            "UPDATE reportes_usuarios
             SET estado = :estado,
                 reviewed_by = :actor,
                 reviewed_at = NOW(),
                 resolution_note = :note,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            ':estado' => $newStatus,
            ':actor' => $actorId,
            ':note' => $resolutionNote !== '' ? $resolutionNote : null,
            ':id' => $reportId,
        ]);

        if ($stmt->rowCount() <= 0) {
            sendJsonResponse(false, 'Reporte no encontrado o sin cambios', [], 404);
        }

        sendJsonResponse(true, 'Reporte actualizado', [
            'report_id' => $reportId,
            'estado' => $newStatus,
        ]);
    }

    sendJsonResponse(false, 'Método no permitido', [], 405);
} catch (Exception $e) {
    sendJsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
}
