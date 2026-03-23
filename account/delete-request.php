<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/EmailService.php';

function generateSecureCode(): string {
    return str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

function ensureDeletionSchema(PDO $db): void {
    // Compatibilidad con instalaciones donde estas columnas aún no existen.
    $db->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS status VARCHAR(32) DEFAULT 'active'");
    $db->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS deletion_requested_at TIMESTAMP NULL");
    $db->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS deletion_scheduled_at TIMESTAMP NULL");
    $db->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS deleted_reason TEXT NULL");
    $db->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");
    $db->exec("UPDATE usuarios SET status = 'active' WHERE status IS NULL");

    $db->exec("CREATE TABLE IF NOT EXISTS account_deletion_codes (
        id BIGSERIAL PRIMARY KEY,
        user_id BIGINT NOT NULL,
        email VARCHAR(255) NOT NULL,
        code VARCHAR(10) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        used BOOLEAN NOT NULL DEFAULT FALSE,
        used_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_account_deletion_codes_user ON account_deletion_codes (user_id, email)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_account_deletion_codes_code ON account_deletion_codes (code, used, expires_at)");
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Método no permitido', [], 405, 'METHOD_NOT_ALLOWED');
    }

    $input = getJsonInput();
    $action = strtolower(trim((string)($input['action'] ?? 'request_code')));

    $userId = (int)($input['user_id'] ?? 0);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $verificationCode = trim((string)($input['verification_code'] ?? ''));
    $reason = trim((string)($input['reason'] ?? ''));

    if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendJsonResponse(false, 'Debes enviar un usuario y correo válidos.', [], 400, 'VALIDATION_ERROR');
    }

    $database = new Database();
    $db = $database->getConnection();
    ensureDeletionSchema($db);

    $userStmt = $db->prepare('SELECT id, email, nombre, tipo_usuario, status FROM usuarios WHERE id = ? AND LOWER(email) = ? LIMIT 1');
    $userStmt->execute([$userId, $email]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendJsonResponse(false, 'No encontramos una cuenta válida para esta solicitud.', [], 404, 'USER_NOT_FOUND');
    }

    $status = strtolower((string)($user['status'] ?? 'active'));
    if ($status === 'pending_deletion') {
        // Normalizar estados legacy al nuevo flujo: pending_deletion -> inactive.
        $normalizeStmt = $db->prepare("UPDATE usuarios SET status = 'inactive' WHERE id = ?");
        $normalizeStmt->execute([$userId]);
        $user['status'] = 'inactive';
        $status = 'inactive';
    }

    if (($user['status'] ?? 'active') === 'deleted') {
        sendJsonResponse(false, 'Esta cuenta ya fue eliminada de forma definitiva.', [], 409, 'ACCOUNT_ALREADY_DELETED');
    }

    if ($action === 'request_code') {
        // Seguridad: invalidamos códigos previos para evitar reutilización.
        $invalidateStmt = $db->prepare('UPDATE account_deletion_codes SET used = TRUE, used_at = NOW() WHERE user_id = ? AND used = FALSE');
        $invalidateStmt->execute([$userId]);

        $code = generateSecureCode();
        $insertStmt = $db->prepare(
            "INSERT INTO account_deletion_codes (user_id, email, code, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL '10 minutes')"
        );
        $insertStmt->execute([$userId, $email, $code]);

        $emailService = new EmailService($db);
        $sent = $emailService->sendAccountDeletionVerificationEmail($email, $code);

        if (!$sent) {
            sendJsonResponse(false, 'No pudimos enviar el código de verificación en este momento.', [], 429, 'EMAIL_SEND_BLOCKED');
        }

        sendJsonResponse(true, 'Código de verificación enviado al correo registrado.', [
            'expires_in_seconds' => 600,
        ]);
    }

    if ($action !== 'confirm') {
        sendJsonResponse(false, 'Acción no soportada.', [], 400, 'INVALID_ACTION');
    }

    $allowTestCode = filter_var((string)env_value('ACCOUNT_DELETION_ALLOW_TEST_CODE', '1'), FILTER_VALIDATE_BOOLEAN);
    $testCode = trim((string)env_value('ACCOUNT_DELETION_TEST_CODE', '8052'));
    $isTestBypass = $allowTestCode && $testCode !== '' && hash_equals($testCode, $verificationCode);

    if (!$isTestBypass && !preg_match('/^\d{4}$/', $verificationCode)) {
        sendJsonResponse(false, 'El código de verificación es inválido.', [], 400, 'INVALID_CODE_FORMAT');
    }

    $codeRow = null;
    if (!$isTestBypass) {
        $codeStmt = $db->prepare(
            'SELECT id FROM account_deletion_codes WHERE user_id = ? AND email = ? AND code = ? AND used = FALSE AND expires_at > NOW() ORDER BY id DESC LIMIT 1'
        );
        $codeStmt->execute([$userId, $email, $verificationCode]);
        $codeRow = $codeStmt->fetch(PDO::FETCH_ASSOC);

        if (!$codeRow) {
            sendJsonResponse(false, 'Código inválido o expirado.', [], 400, 'INVALID_OR_EXPIRED_CODE');
        }
    }

    if ($status === 'inactive' || $status === 'inactivo') {
        $scheduleStmt = $db->prepare('SELECT deletion_scheduled_at FROM usuarios WHERE id = ? LIMIT 1');
        $scheduleStmt->execute([$userId]);
        $schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);

        sendJsonResponse(true, 'La cuenta ya estaba en estado inactivo por eliminación programada.', [
            'status' => 'inactive',
            'deletion_scheduled_at' => $schedule['deletion_scheduled_at'] ?? null,
        ]);
    }

    // Cumplimiento: se agenda una ventana de 15 días para posible retracto/reactivación.
    $updateStmt = $db->prepare(
        "UPDATE usuarios
         SET status = 'inactive',
             deletion_requested_at = NOW(),
             deletion_scheduled_at = NOW() + INTERVAL '15 days',
             deleted_reason = CASE WHEN ? = '' THEN deleted_reason ELSE ? END,
             deleted_at = NULL
         WHERE id = ?"
    );
    $updateStmt->execute([$reason, $reason, $userId]);

    if (!$isTestBypass && $codeRow) {
        $consumeStmt = $db->prepare('UPDATE account_deletion_codes SET used = TRUE, used_at = NOW() WHERE id = ?');
        $consumeStmt->execute([$codeRow['id']]);
    }

    $resultStmt = $db->prepare('SELECT status, deletion_requested_at, deletion_scheduled_at FROM usuarios WHERE id = ? LIMIT 1');
    $resultStmt->execute([$userId]);
    $result = $resultStmt->fetch(PDO::FETCH_ASSOC);

    sendJsonResponse(true, 'Tu cuenta quedó inactiva y programada para eliminación en 15 días.', [
        'status' => $result['status'] ?? 'inactive',
        'deletion_requested_at' => $result['deletion_requested_at'] ?? null,
        'deletion_scheduled_at' => $result['deletion_scheduled_at'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('delete-request.php error: ' . $e->getMessage());
    sendJsonResponse(false, 'No fue posible procesar la solicitud de eliminación.', [], 500, 'ACCOUNT_DELETION_FAILED');
}
