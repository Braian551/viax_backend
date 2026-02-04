<?php
require_once '../config/config.php';

try {
    $input = getJsonInput();

    if (empty($input['email']) || empty($input['device_uuid'])) {
        sendJsonResponse(false, 'Email y device_uuid son requeridos');
    }

    $email = trim($input['email']);
    $deviceUuid = trim($input['device_uuid']);

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

    sendJsonResponse(true, 'Estado de dispositivo obtenido', [
        'exists' => true,
        'status' => $status,
        'user_type' => $user['tipo_usuario']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    sendJsonResponse(false, 'Error: ' . $e->getMessage());
}
