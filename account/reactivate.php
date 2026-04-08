<?php
require_once __DIR__ . '/../config/config.php';

/**
 * Verifica id_token de Google (cliente web o móvil).
 *
 * @return array<string,mixed>|null
 */
function verifyGoogleIdTokenForReactivation(string $idToken, string $webClientId, string $mobileClientId): ?array
{
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($response)) {
        return null;
    }

    $tokenInfo = json_decode($response, true);
    if (!is_array($tokenInfo)) {
        return null;
    }

    $validClientIds = [$webClientId, $mobileClientId];
    if (!isset($tokenInfo['aud']) || !in_array($tokenInfo['aud'], $validClientIds, true)) {
        return null;
    }

    if (isset($tokenInfo['exp']) && (int)$tokenInfo['exp'] < time()) {
        return null;
    }

    return $tokenInfo;
}

/**
 * Obtiene perfil Google desde access_token.
 *
 * @return array<string,mixed>|null
 */
function getGoogleUserInfoForReactivation(string $accessToken): ?array
{
    $url = 'https://www.googleapis.com/oauth2/v3/userinfo';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($response)) {
        return null;
    }

    $profile = json_decode($response, true);
    return is_array($profile) ? $profile : null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Método no permitido', [], 405, 'METHOD_NOT_ALLOWED');
    }

    $input = getJsonInput();
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $idToken = trim((string)($input['id_token'] ?? ''));
    $accessToken = trim((string)($input['access_token'] ?? ''));

    $usingGoogleAuth = $idToken !== '' || $accessToken !== '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendJsonResponse(false, 'Debes enviar un correo válido.', [], 400, 'VALIDATION_ERROR');
    }

    if (!$usingGoogleAuth && $password === '') {
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

    if ($usingGoogleAuth) {
        $googleConfig = require __DIR__ . '/../config/google_oauth.php';
        $webClientId = (string)($googleConfig['web']['client_id'] ?? '');
        $mobileClientId = (string)($googleConfig['mobile']['client_id'] ?? '');

        $googleProfile = null;

        if ($idToken !== '') {
            $googleProfile = verifyGoogleIdTokenForReactivation($idToken, $webClientId, $mobileClientId);
        }

        if (!is_array($googleProfile) && $accessToken !== '') {
            $googleProfile = getGoogleUserInfoForReactivation($accessToken);
        }

        if (!is_array($googleProfile)) {
            sendJsonResponse(false, 'No se pudo validar tu cuenta de Google para reactivación.', [], 401, 'GOOGLE_IDENTITY_INVALID');
        }

        $googleEmail = strtolower(trim((string)($googleProfile['email'] ?? '')));
        if ($googleEmail === '' || !hash_equals($email, $googleEmail)) {
            sendJsonResponse(false, 'El correo de Google no coincide con la cuenta en eliminación.', [], 403, 'GOOGLE_EMAIL_MISMATCH');
        }
    } else {
        // Seguridad: para reactivar por credenciales exigimos contraseña vigente.
        if (!password_verify($password, $user['hash_contrasena'])) {
            sendJsonResponse(false, 'Contraseña incorrecta.', [], 401, 'INVALID_CREDENTIALS');
        }
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
