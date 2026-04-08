<?php
/**
 * get_ticket_logs.php
 * Historial de cambios de un ticket para agentes de soporte.
 */

$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
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

    $ticketId = isset($_GET['ticket_id']) ? (int) $_GET['ticket_id'] : 0;
    $actorId = supportResolveActorId($_GET);

    if ($ticketId <= 0 || $actorId <= 0) {
        supportJsonError('ticket_id y agente_id son requeridos', 400);
    }

    $actor = supportGetActor($conn, $actorId);
    if (!$actor || !$actor['es_agente_soporte']) {
        supportJsonError('Solo agentes de soporte pueden consultar historial', 403);
    }

    $stmt = $conn->prepare(
        "SELECT
            l.id,
            l.accion,
            l.estado_anterior,
            l.estado_nuevo,
            l.prioridad_anterior,
            l.prioridad_nueva,
            l.metadata,
            l.created_at,
            u.nombre as actor_nombre,
            u.apellido as actor_apellido,
            u.tipo_usuario as actor_tipo
        FROM ticket_soporte_logs l
        LEFT JOIN usuarios u ON l.actor_id = u.id
        WHERE l.ticket_id = :ticket_id
        ORDER BY l.created_at DESC"
    );
    $stmt->bindValue(':ticket_id', $ticketId, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['metadata'] = json_decode($row['metadata'] ?? '{}', true) ?? [];
    }

    echo json_encode([
        'success' => true,
        'logs' => $rows,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al consultar historial: ' . $e->getMessage(),
    ]);
}
