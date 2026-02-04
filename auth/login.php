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

    $query = "SELECT id, uuid, nombre, apellido, email, telefono, tipo_usuario, empresa_id, hash_contrasena FROM usuarios WHERE email = ? LIMIT 1";
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
            // Crear tabla con sintaxis PostgreSQL
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

        // Obtener registro del dispositivo
        $dStmt = $db->prepare('SELECT id, trusted, fail_attempts, locked_until FROM user_devices WHERE user_id = ? AND device_uuid = ? LIMIT 1');
        $dStmt->execute([$user['id'], $deviceUuid]);
        $device = $dStmt->fetch(PDO::FETCH_ASSOC);

        if (!$device) {
            // Crear registro no confiable inicial
            $ins = $db->prepare('INSERT INTO user_devices (user_id, device_uuid, trusted) VALUES (?, ?, 0) RETURNING id');
            $ins->execute([$user['id'], $deviceUuid]);
            $result = $ins->fetch(PDO::FETCH_ASSOC);
            $device = [
                'id' => $result['id'],
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
                // Bloquear dispositivo por 15 minutos (sintaxis PostgreSQL)
                $upd = $db->prepare("UPDATE user_devices SET fail_attempts = ?, locked_until = NOW() + INTERVAL '15 minutes' WHERE id = ?");
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

    // ========== EMPRESA STATUS CHECK ==========
    // If user is empresa type, check if empresa is approved
    if ($user['tipo_usuario'] === 'empresa' && !empty($user['empresa_id'])) {
        $empresaQuery = "SELECT estado FROM empresas_transporte WHERE id = ? LIMIT 1";
        $empresaStmt = $db->prepare($empresaQuery);
        $empresaStmt->execute([$user['empresa_id']]);
        $empresa = $empresaStmt->fetch(PDO::FETCH_ASSOC);
        
        // Estado 'activo' significa que la empresa fue aprobada
        if (!$empresa || $empresa['estado'] !== 'activo') {
            $estadoActual = $empresa['estado'] ?? 'desconocido';
            sendJsonResponse(false, 'Tu empresa aún no ha sido aprobada', [
                'empresa_pendiente' => true,
                'estado' => $estadoActual,
                'mensaje' => 'Tu solicitud de registro está en revisión. Te notificaremos cuando sea aprobada.'
            ]);
        }
    }

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
    // Limpiar mensaje de error para evitar caracteres UTF-8 invalidos
    $errorMsg = preg_replace('/[^\x20-\x7E]/', '', $e->getMessage());
    sendJsonResponse(false, 'Error del servidor: ' . $errorMsg);
}

?>
