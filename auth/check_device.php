<?php
/**
 * Endpoint: Verificar estado de dispositivo por usuario.
 *
 * Compatible con Flutter:
 * - Mantiene estructura de respuesta actual.
 * - No cambia nombres de campos esperados por la app.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/RateLimiter.php';
require_once __DIR__ . '/../core/Auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    sendJsonResponse(false, 'Método no permitido');
}

try {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!RateLimiter::allow('rate_limit:check_device:' . $ip, 120, 60)) {
        sendJsonResponse(false, 'Demasiadas solicitudes', [], 429, 'RATE_LIMIT');
    }

    $input = getJsonInput();

    if (empty($input['email']) || empty($input['device_uuid'])) {
        sendJsonResponse(false, 'Email y device_uuid son requeridos');
    }

    $email = strtolower(trim((string) $input['email']));
    $deviceUuid = trim((string) $input['device_uuid']);
    $sessionToken = isset($input['token']) ? trim((string) $input['token']) : null;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendJsonResponse(false, 'Email inválido');
    }
    if ($deviceUuid === '' || strlen($deviceUuid) > 128) {
        sendJsonResponse(false, 'device_uuid inválido');
    }

    // Atajo de lectura por cache de dispositivo.
    $deviceCacheKey = 'user_device_status:' . sha1($email . '|' . $deviceUuid);
    $cachedDeviceStatus = class_exists('Cache') ? Cache::get($deviceCacheKey) : null;
    if ($cachedDeviceStatus) {
        $decoded = json_decode((string) $cachedDeviceStatus, true);
        if (is_array($decoded) && isset($decoded['status'], $decoded['user_type'])) {
            sendJsonResponse(true, 'Estado de dispositivo obtenido', [
                'exists' => true,
                'status' => $decoded['status'],
                'user_type' => $decoded['user_type'],
                'source' => 'cache'
            ]);
        }
    }

    $database = new Database();
    $db = $database->getConnection();

    // Buscar usuario por email
    $stmt = $db->prepare('SELECT id, tipo_usuario FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendJsonResponse(true, 'Usuario no encontrado', [
            'exists' => false,
            'status' => 'user_not_found'
        ]);
    }

    $userId = (int)$user['id'];

    // Asegurar tabla user_devices existe (en caso de entornos sin migración ejecutada)
    try {
        $db->query("SELECT 1 FROM user_devices LIMIT 1");
    } catch (Exception $e) {
        // Intentar crear la tabla mínimamente (sintaxis PostgreSQL)
        $db->exec("CREATE TABLE IF NOT EXISTS user_devices (
            id BIGSERIAL PRIMARY KEY,
            user_id BIGINT NOT NULL,
            device_uuid VARCHAR(100) NOT NULL,
            first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_seen TIMESTAMP DEFAULT NULL,
            trusted SMALLINT NOT NULL DEFAULT 0,
            fail_attempts INT NOT NULL DEFAULT 0,
            locked_until TIMESTAMP DEFAULT NULL,
            UNIQUE (user_id, device_uuid)
        )");
    }

    // Consultar/crear registro del dispositivo
    $stmt = $db->prepare('SELECT id, trusted, fail_attempts, locked_until FROM user_devices WHERE user_id = ? AND device_uuid = ? LIMIT 1');
    $stmt->execute([$userId, $deviceUuid]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        // Crear registro como no confiable por defecto
        $ins = $db->prepare('INSERT INTO user_devices (user_id, device_uuid, trusted) VALUES (?, ?, 0)');
        $ins->execute([$userId, $deviceUuid]);
        $status = 'unknown_device';
    } else {
        // Determinar estado
        $lockedUntil = $device['locked_until'];
        if (!empty($lockedUntil) && strtotime($lockedUntil) > time()) {
            $status = 'locked';
        } else if ((int)$device['trusted'] === 1) {
            $status = 'trusted';
        } else {
            $status = 'needs_verification';
        }

        // Tocar last_seen
        $upd = $db->prepare('UPDATE user_devices SET last_seen = NOW() WHERE id = ?');
        $upd->execute([$device['id']]);
    }

    $responseData = [
        'exists' => true,
        'status' => $status,
        'user_type' => $user['tipo_usuario']
    ];

    // Cache corto para reducir lecturas repetidas en login/checks.
    if (class_exists('Cache')) {
        Cache::set($deviceCacheKey, (string) json_encode([
            'status' => $status,
            'user_type' => $user['tipo_usuario'],
        ]), 60);
    }

    // Si el cliente envía token, guardamos sesión mínima Redis user_session:{token}.
    if ($sessionToken && Auth::isPlausibleToken($sessionToken)) {
        Auth::putSessionInCache($sessionToken, [
            'user_id' => $userId,
            'email' => $email,
            'device_uuid' => $deviceUuid,
            'status' => $status,
            'user_type' => $user['tipo_usuario'],
            'ts' => time(),
        ], 3600);
    }

    sendJsonResponse(true, 'Estado de dispositivo obtenido', $responseData);

} catch (Exception $e) {
    http_response_code(500);
    error_log('check_device.php error: ' . $e->getMessage());
    sendJsonResponse(false, 'Error interno del servidor');
}
