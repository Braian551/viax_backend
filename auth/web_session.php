<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';

header('Cache-Control: no-store');
try {
    $method = Request::method();
    if ($method === 'DELETE') {
        SessionAccess::requireBrowserOrigin();
        SessionAccess::revokeWebSession();
        sendJsonResponse(true, 'Sesión cerrada.');
    }
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST, DELETE');
        sendJsonResponse(false, 'Método no permitido.', [], 405);
    }

    $user = SessionAccess::requireUser((new Database())->getConnection(), true);
    if ($method === 'POST' && !SessionAccess::renewWebSession()) {
        sendJsonResponse(false, 'Tu sesión venció. Inicia sesión para continuar.', [], 401, 'SESSION_EXPIRED');
    }
    sendJsonResponse(true, 'Sesión activa.', ['user' => $user]);
} catch (Throwable $error) {
    error_log('VIAX web session: ' . get_class($error));
    sendJsonResponse(false, 'El servicio de sesión no está disponible.', [], 503, 'SESSION_UNAVAILABLE');
}
