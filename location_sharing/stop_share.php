<?php
/**
 * Location Sharing API — Stop Sharing
 * 
 * POST /location_sharing/stop_share.php
 * Body: { token }
 * 
 * Deactivates the share session.
 */
require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, 'Método no permitido', [], 405);
}

$input = getJsonInput();
$token = $input['token'] ?? '';

if (empty($token)) {
    sendJsonResponse(false, 'El parámetro token es obligatorio', [], 400);
}

try {
    $db = (new Database())->getConnection();

    $stmt = $db->prepare("
        UPDATE location_share_tokens
        SET is_active = false
        WHERE token = :token AND is_active = true
    ");
    $stmt->execute([':token' => $token]);

    if ($stmt->rowCount() === 0) {
        sendJsonResponse(false, 'Sesión no encontrada o ya finalizada', [], 404);
    }

    sendJsonResponse(true, 'Sesión de compartir finalizada');

} catch (Exception $e) {
    sendJsonResponse(false, 'Error deteniendo sesión: ' . $e->getMessage(), [], 500);
}
