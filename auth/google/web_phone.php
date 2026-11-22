<?php

require_once __DIR__ . '/../../middleware/SessionAccess.php';

if (Request::method() !== 'POST') {
    header('Allow: POST');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}
try {
    $db = (new Database())->getConnection();
    $user = SessionAccess::requireUser($db, true);
    $phone = getJsonInput()['phone'] ?? null;
    if (!is_string($phone) || !preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) {
        sendJsonResponse(false, 'Ingresa un número de teléfono válido.', [], 400);
    }
    $update = $db->prepare(
        "UPDATE usuarios SET telefono = :phone
         WHERE id = :id AND google_id IS NOT NULL AND google_id <> ''
         AND status = 'active' AND es_activo = 1 AND COALESCE(trim(telefono), '') = ''
         RETURNING telefono"
    );
    $update->execute(['id' => $user['id'], 'phone' => $phone]);
    $saved = $update->fetchColumn();
    if (!$saved) {
        sendJsonResponse(false, 'No se puede agregar un teléfono a esta cuenta.', [], 409);
    }
    $user['telefono'] = $saved;
    sendJsonResponse(true, 'Teléfono guardado.', ['user' => $user]);
} catch (Throwable $error) {
    error_log('VIAX Google phone: ' . get_class($error));
    sendJsonResponse(false, 'No se pudo guardar el teléfono.', [], 503);
}
