<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';

header('Cache-Control: no-store');
if (Request::method() !== 'POST') {
    header('Allow: POST');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}
SessionAccess::requireBrowserOrigin();
if (!RateLimiter::check($_SERVER['REMOTE_ADDR'] ?? 'unknown', 15, 300, 'web_login')) {
    header('Retry-After: 300');
    sendJsonResponse(false, 'Demasiados intentos. Intenta nuevamente en unos minutos.', [], 429, 'RATE_LIMIT_EXCEEDED');
}
define('VIAX_WEB_LOGIN', true);
require __DIR__ . '/login.php';
