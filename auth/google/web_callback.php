<?php

require_once __DIR__ . '/../../services/GoogleWebIdentity.php';
require_once __DIR__ . '/../../services/GoogleWebChallenge.php';
require_once __DIR__ . '/../../services/GoogleWebAccounts.php';

header('Cache-Control: no-store');
if (Request::method() !== 'POST') {
    header('Allow: POST');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}
SessionAccess::requireBrowserOrigin();
if (!RateLimiter::check($_SERVER['REMOTE_ADDR'] ?? 'unknown', 15, 60, 'google_web_callback')) {
    sendJsonResponse(false, 'Demasiados intentos. Espera un minuto.', [], 429);
}

$input = getJsonInput();
$credential = $input['credential'] ?? null;
if (!is_string($credential) || strlen($credential) > 8192 || substr_count($credential, '.') !== 2) {
    sendJsonResponse(false, 'Credencial de Google inválida.', [], 400);
}

try {
    $config = require __DIR__ . '/../../config/google_oauth.php';
    try {
        $claims = GoogleWebIdentity::verify($credential, $config['web']['client_id']);
    } catch (UnexpectedValueException | InvalidArgumentException | DomainException $error) {
        sendJsonResponse(false, 'Credencial de Google inválida o vencida.', [], 401);
    }
    if (!GoogleWebChallenge::consume($claims['nonce'])) {
        sendJsonResponse(false, 'La verificación venció. Vuelve a elegir tu cuenta de Google.', [], 401);
    }

    $db = (new Database())->getConnection();
    $db->beginTransaction();
    $user = GoogleWebAccounts::resolve($db, $claims);
    if (!SessionAccess::createWebSession((int)$user['id'], static fn () => $db->commit())) {
        $db->rollBack();
        sendJsonResponse(false, 'El servicio de sesión no está disponible.', [], 503);
    }
    unset($user['status'], $user['es_activo']);
    sendJsonResponse(true, 'Sesión iniciada.', ['user' => $user, 'requires_phone' => empty($user['telefono'])]);
} catch (DomainException $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    sendJsonResponse(false, $error->getMessage(), [], 403);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('VIAX Google Web: ' . get_class($error));
    sendJsonResponse(false, 'No pudimos iniciar sesión con Google en este momento.', [], 503);
}
