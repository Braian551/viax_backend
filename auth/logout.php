<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sendJsonResponse(false, 'Metodo no permitido', [], 405, 'METHOD_NOT_ALLOWED');
}

try {
    $input = getJsonInput();

    $bearerToken = Auth::bearerToken();
    $accessToken = trim((string)($input['access_token'] ?? $bearerToken ?? ''));
    $refreshToken = trim((string)($input['refresh_token'] ?? ''));

    if ($accessToken === '' && $refreshToken === '') {
        sendJsonResponse(false, 'access_token o refresh_token es requerido', [], 400, 'VALIDATION_ERROR');
    }

    if ($accessToken !== '') {
        Auth::revokeSession($accessToken, 'logout');
    }
    if ($refreshToken !== '') {
        Auth::revokeRefreshToken($refreshToken);
    }

    sendJsonResponse(true, 'Sesion cerrada correctamente');
} catch (Throwable $e) {
    error_log('[auth][logout] ' . $e->getMessage());
    sendJsonResponse(false, 'No se pudo cerrar sesion', [], 500, 'SERVER_ERROR');
}
