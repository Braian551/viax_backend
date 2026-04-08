<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sendJsonResponse(false, 'Metodo no permitido', [], 405, 'METHOD_NOT_ALLOWED');
}

try {
    $input = getJsonInput();
    $refreshToken = trim((string)($input['refresh_token'] ?? ''));

    if ($refreshToken === '') {
        sendJsonResponse(false, 'refresh_token es requerido', [], 400, 'VALIDATION_ERROR');
    }

    $rotated = Auth::refreshSession($refreshToken);
    if (!is_array($rotated)) {
        sendJsonResponse(false, 'Refresh token invalido o expirado', [], 401, 'INVALID_REFRESH_TOKEN');
    }

    sendJsonResponse(true, 'Token renovado', [
        'access_token' => $rotated['access_token'],
        'refresh_token' => $rotated['refresh_token'],
        'expires_in' => $rotated['expires_in'],
        'user_id' => $rotated['user_id'],
    ]);
} catch (Throwable $e) {
    error_log('[auth][refresh_token] ' . $e->getMessage());
    sendJsonResponse(false, 'No se pudo renovar token', [], 500, 'SERVER_ERROR');
}
