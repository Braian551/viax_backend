<?php

require_once __DIR__ . '/../services/LegalAcceptance.php';

if (Request::method() !== 'POST') {
    header('Allow: POST');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}

try {
    $db = (new Database())->getConnection();
    $user = SessionAccess::requireUser($db);
    $data = getJsonInput();
    $userId = (int)$user['id'];
    $role = LegalAcceptance::role($user['tipo_usuario']);
    if (filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT) !== $userId
        || $role === null || LegalAcceptance::role($data['role'] ?? null) !== $role) {
        sendJsonResponse(false, 'Acceso denegado.', [], 403);
    }

    $version = $data['version'] ?? null;
    $deviceId = $data['device_id'] ?? null;
    if (!is_string($version) || $version === '' || strlen($version) > 20
        || !is_string($deviceId) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $deviceId)
        || $deviceId === 'system_migration') {
        sendJsonResponse(false, 'Versión o dispositivo inválido.', [], 400);
    }
    if (!LegalAcceptance::record($db, $userId, $role, $version, $deviceId)) {
        sendJsonResponse(false, 'Los términos cambiaron. Consulta y acepta la versión vigente.', [], 409, 'LEGAL_VERSION_EXPIRED');
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => true, 'accepted' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('VIAX legal acceptance: ' . get_class($error));
    sendJsonResponse(false, 'No pudimos registrar la aceptación.', [], 503);
}
