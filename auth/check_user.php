<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/RateLimiter.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if (Request::method() !== 'POST') {
    header('Allow: POST');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}
if (!RateLimiter::check($_SERVER['REMOTE_ADDR'] ?? 'unknown', 60, 60, 'check_user')) {
    sendJsonResponse(false, 'Demasiadas solicitudes.', [], 429);
}
try {
    $email = getJsonInput()['email'] ?? null;
    if (!is_string($email) || strlen($email) > 254 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
        sendJsonResponse(false, 'Correo inválido.', [], 400);
    }
    $db = (new Database())->getConnection();
    $stmt = $db->prepare('SELECT tipo_usuario FROM usuarios WHERE lower(email) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $role = $stmt->fetchColumn();
    echo json_encode(['success' => true, 'exists' => $role !== false, 'user_type' => $role ?: null]);
} catch (Throwable $error) {
    error_log('VIAX check user: ' . get_class($error));
    sendJsonResponse(false, 'No pudimos consultar el correo.', [], 503);
}
