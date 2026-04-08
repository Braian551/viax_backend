<?php
/**
 * Endpoint: Consultar estado de bloqueo entre dos usuarios.
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/BlockHelper.php';

try {
    $actorId = isset($_GET['actor_id']) ? (int)$_GET['actor_id'] : 0;
    $otherUserId = isset($_GET['other_user_id']) ? (int)$_GET['other_user_id'] : 0;

    if ($actorId <= 0 || $otherUserId <= 0) {
        throw new Exception('actor_id y other_user_id son requeridos');
    }

    $database = new Database();
    $db = $database->getConnection();

    $state = BlockHelper::getBlockState($db, $actorId, $otherUserId);
    $hasActiveTrip = BlockHelper::hasActiveTrip($db, $actorId, $otherUserId);
    $hasSharedTrip = BlockHelper::hasSharedTrip($db, $actorId, $otherUserId);

    echo json_encode([
        'success' => true,
        'data' => [
            'actor_id' => $actorId,
            'other_user_id' => $otherUserId,
            'blocked_by_me' => $state['blocked_by_me'],
            'blocked_me' => $state['blocked_me'],
            'either_blocked' => $state['either_blocked'],
            'has_active_trip' => $hasActiveTrip,
            'has_shared_trip' => $hasSharedTrip,
            'can_send_message' => $hasActiveTrip || !$state['either_blocked'],
            'can_match_future_trips' => !$state['either_blocked'],
        ],
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
