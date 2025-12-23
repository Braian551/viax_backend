<?php
require_once '../config/config.php';

try {
    $input = getJsonInput();

    if (empty($input['email']) || empty($input['password'])) {
        sendJsonResponse(false, 'Email y password son requeridos');
    }

    $deviceUuid = isset($input['device_uuid']) ? trim($input['device_uuid']) : null;

    $email = $input['email'];
    $password = $input['password'];

    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT id, uuid, nombre, apellido, email, telefono, tipo_usuario, hash_contrasena FROM usuarios WHERE email = ? LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendJsonResponse(false, 'Usuario no encontrado');
    }

    // Device security handling (lazy ensure table exists)
    if ($deviceUuid) {
        try {
            $db->query('SELECT 1 FROM user_devices LIMIT 1');
        } catch (Exception $e) {
            $db->exec("CREATE TABLE IF NOT EXISTS user_devices (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                device_uuid VARCHAR(100) NOT NULL,
                first_seen TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                trusted TINYINT(1) NOT NULL DEFAULT 0,
                fail_attempts INT NOT NULL DEFAULT 0,
                locked_until TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY idx_user_device_unique (user_id, device_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }

        // Obtener registro del dispositivo
        $dStmt = $db->prepare('SELECT id, trusted, fail_attempts, locked_until FROM user_devices WHERE user_id = ? AND device_uuid = ? LIMIT 1');
        $dStmt->execute([$user['id'], $deviceUuid]);
        $device = $dStmt->fetch(PDO::FETCH_ASSOC);

        if (!$device) {
            // Crear registro no confiable inicial
            $ins = $db->prepare('INSERT INTO user_devices (user_id, device_uuid, trusted) VALUES (?, ?, 0)');
            $ins->execute([$user['id'], $deviceUuid]);
            $device = [
                'id' => $db->lastInsertId(),
                'trusted' => 0,
                'fail_attempts' => 0,
                'locked_until' => null
            ];
        }

        // Verificar si está bloqueado
        if (!empty($device['locked_until']) && strtotime($device['locked_until']) > time()) {
            sendJsonResponse(false, 'Dispositivo bloqueado temporalmente', [
                'too_many_attempts' => true,
                'locked_until' => $device['locked_until']
            ]);
        }
    }

    // Verificar contraseña
    if (!password_verify($password, $user['hash_contrasena'])) {
        // Incrementar intentos fallidos si hay deviceUuid
        if ($deviceUuid && isset($device['id'])) {
            $failAttempts = (int)$device['fail_attempts'] + 1;
            $lockApplied = false;
            if ($failAttempts >= 5) {
                // Bloquear dispositivo por 15 minutos
                $upd = $db->prepare('UPDATE user_devices SET fail_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?');
                $upd->execute([$failAttempts, $device['id']]);
                $lockApplied = true;
            } else {
                $upd = $db->prepare('UPDATE user_devices SET fail_attempts = ? WHERE id = ?');
                $upd->execute([$failAttempts, $device['id']]);
            }
            sendJsonResponse(false, 'Contraseña incorrecta', [
                'fail_attempts' => $failAttempts,
                'too_many_attempts' => $lockApplied
            ]);
        }
        sendJsonResponse(false, 'Contraseña incorrecta');
    }

    // No devolver hash
    unset($user['hash_contrasena']);

    // Resetear intentos fallidos y actualizar last_seen si hay device
    // IMPORTANTE: Marcar este dispositivo como el único confiable (invalidar otros)
    if ($deviceUuid && isset($device['id'])) {
        // Primero, marcar todos los dispositivos del usuario como NO confiables
        $invalidate = $db->prepare('UPDATE user_devices SET trusted = 0 WHERE user_id = ?');
        $invalidate->execute([$user['id']]);
        
        // Luego, marcar solo este dispositivo como confiable y resetear intentos
        $upd = $db->prepare('UPDATE user_devices SET fail_attempts = 0, last_seen = NOW(), trusted = 1 WHERE id = ?');
        $upd->execute([$device['id']]);
    }

    // Registrar login en logs de auditoría
    try {
        $logQuery = "INSERT INTO logs_auditoria (usuario_id, accion, descripcion, ip_address, user_agent) 
                     VALUES (?, 'login', 'Usuario inició sesión exitosamente', ?, ?)";
        $logStmt = $db->prepare($logQuery);
        $logStmt->execute([
            $user['id'], 
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        // No detener el login si falla el log
        error_log("Error al registrar log de auditoría: " . $e->getMessage());
    }

    sendJsonResponse(true, 'Login exitoso', ['user' => $user]);

} catch (Exception $e) {
    http_response_code(500);
    sendJsonResponse(false, 'Error: ' . $e->getMessage());
}

?>
