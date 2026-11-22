<?php

require_once __DIR__ . '/../../services/GoogleWebChallenge.php';

header('Cache-Control: no-store');
if (Request::method() !== 'POST') {
    header('Allow: POST');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}
SessionAccess::requireBrowserOrigin();
if (!RateLimiter::check($_SERVER['REMOTE_ADDR'] ?? 'unknown', 25, 60, 'google_web_challenge')) {
    sendJsonResponse(false, 'Demasiados intentos. Espera un minuto.', [], 429);
}
$nonce = GoogleWebChallenge::issue();
if ($nonce === null) {
    sendJsonResponse(false, 'No se pudo iniciar Google Sign-In.', [], 503);
}
$config = require __DIR__ . '/../../config/google_oauth.php';
sendJsonResponse(true, 'Verificación preparada.', ['nonce' => $nonce, 'client_id' => $config['web']['client_id']]);
