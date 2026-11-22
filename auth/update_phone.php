<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';

if (Request::method() !== 'POST') {
    header('Allow: POST');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}
if (Auth::bearerToken() === null) {
    sendJsonResponse(false, 'Inicia sesión para completar el teléfono.', [], 401);
}

try {
    $db = (new Database())->getConnection();
    $user = SessionAccess::requireUser($db);
    $input = getJsonInput();
    if (isset($input['user_id']) && filter_var($input['user_id'], FILTER_VALIDATE_INT) !== (int)$user['id']) {
        sendJsonResponse(false, 'Acceso denegado.', [], 403);
    }
    $phone = $input['phone'] ?? null;
    if (!is_string($phone) || !preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) {
        sendJsonResponse(false, 'Ingresa un número de teléfono válido.', [], 400);
    }
    $update = $db->prepare(
        "UPDATE usuarios SET telefono = ?, fecha_actualizacion = CURRENT_TIMESTAMP
         WHERE id = ? AND google_id IS NOT NULL AND status = 'active' AND es_activo = 1
         AND COALESCE(trim(telefono), '') = ''
         AND NOT EXISTS (SELECT 1 FROM usuarios WHERE telefono = ?)
         RETURNING id, uuid, nombre, apellido, email, telefono, tipo_usuario, foto_perfil, es_verificado, empresa_id"
    );
    $update->execute([$phone, $user['id'], $phone]);
    $updated = $update->fetch(PDO::FETCH_ASSOC);
    if (!$updated) {
        sendJsonResponse(false, 'El teléfono ya está registrado o esta cuenta no admite ese cambio.', [], 409);
    }
    sendJsonResponse(true, 'Teléfono actualizado.', ['user' => $updated]);
} catch (Throwable $error) {
    error_log('VIAX native phone update: ' . get_class($error));
    sendJsonResponse(false, 'No se pudo guardar el teléfono.', [], 503);
}
