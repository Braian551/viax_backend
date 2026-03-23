<?php
/**
 * Endpoint: Bloquear usuario
 * Reglas:
 * - No permite auto-bloqueo.
 * - Solo permite bloquear si existe al menos un viaje compartido.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
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
    $solicitudId = isset($input['solicitud_id']) ? (int)$input['solicitud_id'] : null;
    $reason = isset($input['reason']) ? trim((string)$input['reason']) : null;

    if ($actorId <= 0 || $blockedUserId <= 0) {
        throw new Exception('actor_id y blocked_user_id son requeridos');
    }
    if ($actorId === $blockedUserId) {
        throw new Exception('No puedes bloquearte a ti mismo');
    }

    $database = new Database();
    $db = $database->getConnection();

    $stmtUsers = $db->prepare("SELECT id FROM usuarios WHERE id IN (?, ?) LIMIT 2");
    $stmtUsers->execute([$actorId, $blockedUserId]);
    $users = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);
    if (count($users) < 2) {
        throw new Exception('Usuario no encontrado');
    }

    if (!BlockHelper::hasSharedTrip($db, $actorId, $blockedUserId, $solicitudId)) {
        throw new Exception('Solo puedes bloquear usuarios con los que hayas tenido un viaje');
    }

    $stmtUpsert = $db->prepare(
        "
        INSERT INTO blocked_users (user_id, blocked_user_id, reason, active, blocked_at, unblocked_at, updated_at)
        VALUES (?, ?, ?, true, NOW(), NULL, NOW())
        ON CONFLICT (user_id, blocked_user_id)
        DO UPDATE SET
            reason = EXCLUDED.reason,
            active = true,
            blocked_at = NOW(),
            unblocked_at = NULL,
            updated_at = NOW()
        "
    );
    $stmtUpsert->execute([$actorId, $blockedUserId, $reason !== '' ? $reason : null]);

    $state = BlockHelper::getBlockState($db, $actorId, $blockedUserId);
    $activeTrip = BlockHelper::hasActiveTrip($db, $actorId, $blockedUserId);

    echo json_encode([
        'success' => true,
        'message' => 'Usuario bloqueado correctamente',
        'data' => [
            'actor_id' => $actorId,
            'blocked_user_id' => $blockedUserId,
            'blocked_by_me' => $state['blocked_by_me'],
            'blocked_me' => $state['blocked_me'],
            'either_blocked' => $state['either_blocked'],
            'has_active_trip' => $activeTrip,
            'can_send_message' => $activeTrip,
        ],
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
