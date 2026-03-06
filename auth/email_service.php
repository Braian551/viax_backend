<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/EmailService.php';

try {
    $input = getJsonInput();
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $code = (string) ($input['code'] ?? '');
    $type = $input['type'] ?? 'verification';

    if (!$email || strlen($code) !== 4) {
        sendJsonResponse(false, 'Datos incompletos o inválidos (se esperan 4 dígitos).', [], 400, 'VALIDATION_ERROR');
    }

    $emailService = new EmailService();

    if ($type === 'password_recovery') {
        $sent = $emailService->sendPasswordResetEmail($email, $code);
    } else {
        $sent = $emailService->sendVerificationEmail($email, $code);
    }

    if (!$sent) {
        sendJsonResponse(false, 'No se pudo enviar el correo en este momento. Intenta más tarde.', [], 429, 'EMAIL_SEND_BLOCKED');
    }

    sendJsonResponse(true, 'Correo enviado correctamente.');
} catch (Throwable $e) {
    error_log('Email service error: ' . $e->getMessage());
    sendJsonResponse(false, 'No se pudo enviar el correo en este momento. Intenta más tarde.', [], 500, 'EMAIL_SEND_FAILED');
}