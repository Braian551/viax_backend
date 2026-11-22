<?php

require_once __DIR__ . '/../../services/GoogleWebIdentity.php';
require_once __DIR__ . '/../../services/GoogleWebAccounts.php';

header('Cache-Control: no-store');
if (Request::method() !== 'POST') {
    header('Allow: POST');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}
if (!RateLimiter::check($_SERVER['REMOTE_ADDR'] ?? 'unknown', 15, 60, 'google_native_login')) {
    sendJsonResponse(false, 'Demasiados intentos. Espera un minuto.', [], 429);
}

$input = getJsonInput();
$credential = $input['id_token'] ?? null;
if (!is_string($credential) || strlen($credential) > 8192 || substr_count($credential, '.') !== 2) {
    sendJsonResponse(false, 'Se requiere un ID token válido de Google.', [], 400, 'GOOGLE_TOKEN_REQUIRED');
}
$deviceId = $input['device_uuid'] ?? null;
if ($deviceId !== null && (!is_string($deviceId) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $deviceId))) {
    sendJsonResponse(false, 'Identificador del dispositivo inválido.', [], 400);
}

try {
    $config = require __DIR__ . '/../../config/google_oauth.php';
    try {
        // The Flutter Google SDK requests the Web client ID as serverClientId.
        $claims = GoogleWebIdentity::verify(
            $credential,
            $config['web']['client_id'],
            false,
            $config['android_client_ids']
        );
    } catch (UnexpectedValueException | InvalidArgumentException | DomainException $error) {
        sendJsonResponse(false, 'El ID token de Google es inválido o venció.', [], 401, 'INVALID_GOOGLE_TOKEN');
    }
    $user = GoogleWebAccounts::resolve((new Database())->getConnection(), $claims, false);
    $access = Auth::generateAccessToken();
    $refresh = Auth::generateRefreshToken();
    $ttl = max(60, (int)env_value('AUTH_ACCESS_TOKEN_TTL', 3600));
    if (!Auth::putSessionInCache($access, [
        'user_id' => (int)$user['id'],
        'email' => $user['email'],
        'device_uuid' => $deviceId,
        'status' => $user['status'],
        'user_type' => $user['tipo_usuario'],
        'refresh_token' => $refresh,
    ], $ttl)) {
        sendJsonResponse(false, 'El servicio de sesión no está disponible.', [], 503);
    }
    unset($user['status'], $user['es_activo']);
    sendJsonResponse(true, 'Sesión iniciada.', [
        'user' => $user,
        'access_token' => $access,
        'refresh_token' => $refresh,
        'expires_in' => $ttl,
        'is_new_user' => false,
        'requires_phone' => empty($user['telefono']),
    ]);
} catch (DomainException $error) {
    sendJsonResponse(false, $error->getMessage(), [], 403);
} catch (Throwable $error) {
    error_log('VIAX Google native login: ' . get_class($error));
    sendJsonResponse(false, 'No pudimos iniciar sesión con Google en este momento.', [], 503);
}
