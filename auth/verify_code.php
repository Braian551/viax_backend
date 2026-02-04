<?php
require_once '../config/config.php';

// Usar la misma conexión y helpers que el resto del módulo auth
try {
    $database = new Database();
    $pdo = $database->getConnection();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos: ' . $e->getMessage()]);
    exit;
}

// Procesar la solicitud
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $code = $input['code'] ?? '';
    
    if (!$email || empty($code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Datos incompletos o inválidos']);
        exit;
    }
    $deviceUuid = isset($input['device_uuid']) ? trim($input['device_uuid']) : null;
    $markDeviceTrusted = isset($input['mark_device_trusted']) ? (bool)$input['mark_device_trusted'] : false;

    try {
        // Verificar si la tabla existe
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'verification_codes'")->fetch();
        if (!$tableCheck) {
            throw new Exception("La tabla verification_codes no existe en la base de datos");
        }
        
        // Buscar el código en la base de datos
        $stmt = $pdo->prepare("SELECT * FROM verification_codes WHERE email = ? AND code = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email, $code]);
        $verificationCode = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($verificationCode) {
            // Marcar el código como usado
            $updateStmt = $pdo->prepare("UPDATE verification_codes SET used = 1 WHERE id = ?");
            $updateStmt->execute([$verificationCode['id']]);

            // Si viene de challenge de dispositivo, marcar dispositivo como confiable y limpiar bloqueo
            if ($markDeviceTrusted && $deviceUuid) {
                try {
                    // Asegurar tabla user_devices
                    $pdo->query('SELECT 1 FROM user_devices LIMIT 1');
                } catch (Exception $e) {
                    // Sintaxis PostgreSQL
                    $pdo->exec("CREATE TABLE IF NOT EXISTS user_devices (
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

                // Obtener usuario
                $uStmt = $pdo->prepare('SELECT id, tipo_usuario FROM usuarios WHERE email = ? LIMIT 1');
                $uStmt->execute([$email]);
                $user = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    // IMPORTANTE: Invalidar todos los dispositivos previos del usuario
                    $invalidateAll = $pdo->prepare('UPDATE user_devices SET trusted = 0 WHERE user_id = ?');
                    $invalidateAll->execute([$user['id']]);
                    
                    // Upsert del dispositivo y marcar como el ÚNICO confiable
                    $dSel = $pdo->prepare('SELECT id FROM user_devices WHERE user_id = ? AND device_uuid = ? LIMIT 1');
                    $dSel->execute([$user['id'], $deviceUuid]);
                    $dev = $dSel->fetch(PDO::FETCH_ASSOC);
                    if ($dev) {
                        $updDev = $pdo->prepare('UPDATE user_devices SET trusted = 1, fail_attempts = 0, locked_until = NULL, last_seen = NOW() WHERE id = ?');
                        $updDev->execute([$dev['id']]);
                    } else {
                        $insDev = $pdo->prepare('INSERT INTO user_devices (user_id, device_uuid, trusted) VALUES (?, ?, 1)');
                        $insDev->execute([$user['id'], $deviceUuid]);
                    }
                }
            }

            echo json_encode(['success' => true, 'message' => 'Código verificado correctamente']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Código inválido o expirado']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        error_log("Error en verifi_code: " . $e->getMessage());
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
}
?>