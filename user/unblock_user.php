<?php
/**
 * Endpoint: Desbloquear usuario
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/BlockHelper.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $actorId = isset($input['actor_id']) ? (int)$input['actor_id'] : 0;
    $blockedUserId = isset($input['blocked_user_id']) ? (int)$input['blocked_user_id'] : 0;

    if ($actorId <= 0 || $blockedUserId <= 0) {
        throw new Exception('actor_id y blocked_user_id son requeridos');
    }

    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare(
        "
        UPDATE blocked_users
        SET active = false,
            unblocked_at = NOW(),
            updated_at = NOW()
        WHERE user_id = ?
          AND blocked_user_id = ?
          AND active = true
        "
    );
    $stmt->execute([$actorId, $blockedUserId]);

    $state = BlockHelper::getBlockState($db, $actorId, $blockedUserId);
    $activeTrip = BlockHelper::hasActiveTrip($db, $actorId, $blockedUserId);

    echo json_encode([
        'success' => true,
        'message' => 'Usuario desbloqueado correctamente',
        'data' => [
            'actor_id' => $actorId,
            'blocked_user_id' => $blockedUserId,
            'blocked_by_me' => $state['blocked_by_me'],
            'blocked_me' => $state['blocked_me'],
            'either_blocked' => $state['either_blocked'],
            'has_active_trip' => $activeTrip,
            'can_send_message' => $activeTrip || !$state['either_blocked'],
        ],
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
