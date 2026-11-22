<?php

require_once __DIR__ . '/../services/LegalAcceptance.php';

header('Cache-Control: no-store');
if (Request::method() !== 'GET') {
    header('Allow: GET');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}

try {
    $role = LegalAcceptance::role($_GET['role'] ?? 'cliente');
    if ($role === null) {
        sendJsonResponse(false, 'Rol inválido.', [], 400);
    }

    $db = (new Database())->getConnection();
    $hasSession = Request::header('Authorization') !== null || isset($_COOKIE['__Host-viax-session']);
    $user = $hasSession ? SessionAccess::requireUser($db) : null;
    if ($user !== null && isset($_GET['user_id'])) {
        if (filter_var($_GET['user_id'], FILTER_VALIDATE_INT) !== (int)$user['id']
            || $role !== LegalAcceptance::role($user['tipo_usuario'])) {
            sendJsonResponse(false, 'Acceso denegado.', [], 403);
        }
    }

    $document = LegalAcceptance::current($db, $role);
    $result = [
        'success' => true, 'role' => $role, 'current_version' => $document['version'],
        'content_hash' => $document['content_hash'],
    ];
    if ($user !== null && isset($_GET['user_id'])) {
        $result += LegalAcceptance::status($db, (int)$user['id'], $role, $document);
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('VIAX legal status: ' . get_class($error));
    sendJsonResponse(false, 'No pudimos consultar los términos vigentes.', [], 503);
}
