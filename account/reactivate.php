<?php
require_once __DIR__ . '/../config/config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Método no permitido', [], 405, 'METHOD_NOT_ALLOWED');
    }

    $input = getJsonInput();
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        sendJsonResponse(false, 'Debes enviar correo y contraseña válidos.', [], 400, 'VALIDATION_ERROR');
    }

    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare(
        'SELECT id, uuid, nombre, apellido, email, telefono, tipo_usuario, empresa_id, hash_contrasena, status, deletion_scheduled_at
         FROM usuarios
         WHERE LOWER(email) = ?
         LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendJsonResponse(false, 'No encontramos una cuenta con ese correo.', [], 404, 'USER_NOT_FOUND');
    }

    $status = strtolower((string)($user['status'] ?? 'active'));
    if (!in_array($status, ['inactive', 'inactivo', 'pending_deletion'], true)) {
        sendJsonResponse(false, 'La cuenta no está en estado inactivo por eliminación programada.', [], 409, 'ACCOUNT_NOT_PENDING_DELETION');
    }

    // Seguridad: para reactivar exigimos autenticación fuerte con contraseña vigente.
    if (!password_verify($password, $user['hash_contrasena'])) {
        sendJsonResponse(false, 'Contraseña incorrecta.', [], 401, 'INVALID_CREDENTIALS');
    }

    $updateStmt = $db->prepare(
        "UPDATE usuarios
         SET status = 'active',
             deletion_requested_at = NULL,
             deletion_scheduled_at = NULL,
             deleted_reason = NULL,
             deleted_at = NULL
         WHERE id = ?"
    );
    $updateStmt->execute([$user['id']]);

    unset($user['hash_contrasena']);
    $user['status'] = 'active';
    $user['deletion_scheduled_at'] = null;

    sendJsonResponse(true, 'Cuenta reactivada correctamente. ¡Bienvenido de nuevo a Viax!', [
        'user' => $user,
        'welcome_back' => true,
    ]);
} catch (Throwable $e) {
    error_log('reactivate.php error: ' . $e->getMessage());
    sendJsonResponse(false, 'No fue posible reactivar la cuenta.', [], 500, 'ACCOUNT_REACTIVATION_FAILED');
}
