<?php
/**
 * Google OAuth Callback Handler
 * 
 * Este endpoint maneja el flujo de autenticación con Google OAuth 2.0
 * Soporta tanto registro como inicio de sesión de usuarios.
 * 
 * URL: /auth/google/callback.php
 * Método: POST (desde la app con id_token de Google)
 */

require_once '../../config/config.php';

// Cargar configuración de Google OAuth
$googleConfig = require_once '../../config/google_oauth.php';
$GOOGLE_WEB_CLIENT_ID = $googleConfig['web']['client_id'];
$GOOGLE_MOBILE_CLIENT_ID = $googleConfig['mobile']['client_id'];

try {
    $input = getJsonInput();
    
    // Validar que se recibió el token de Google
    if (empty($input['id_token']) && empty($input['access_token'])) {
        sendJsonResponse(false, 'Se requiere id_token o access_token de Google');
    }
    
    // Obtener información del usuario de Google
    $googleUser = null;
    
    if (!empty($input['id_token'])) {
        // Verificar el id_token con Google (acepta web o móvil)
        $googleUser = verifyGoogleIdToken($input['id_token'], $GOOGLE_WEB_CLIENT_ID, $GOOGLE_MOBILE_CLIENT_ID);
    } else if (!empty($input['access_token'])) {
        // Obtener info del usuario con el access_token
        $googleUser = getGoogleUserInfo($input['access_token']);
    }
    
    if (!$googleUser || empty($googleUser['email'])) {
        sendJsonResponse(false, 'No se pudo verificar la identidad con Google');
    }
    
    // Extraer información del usuario de Google
    $email = $googleUser['email'];
    $googleId = $googleUser['sub'] ?? $googleUser['id'] ?? null;
    $nombre = $googleUser['given_name'] ?? $googleUser['name'] ?? 'Usuario';
    $apellido = $googleUser['family_name'] ?? '';
    $fotoPerfil = $googleUser['picture'] ?? null;
    $emailVerificado = $googleUser['email_verified'] ?? true;
    
    // Conectar a la base de datos
    $database = new Database();
    $db = $database->getConnection();
    
    // Buscar si el usuario ya existe por email o google_id
    $query = "SELECT id, uuid, nombre, apellido, email, telefono, tipo_usuario, 
                     foto_perfil, es_verificado, google_id, empresa_id
              FROM usuarios 
              WHERE email = :email OR google_id = :google_id
              LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':email' => $email,
        ':google_id' => $googleId
    ]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $isNewUser = false;
    $userId = null;
    $user = null;
    
    if ($existingUser) {
        // Usuario existente - actualizar información de Google si es necesario
        $userId = $existingUser['id'];
        
        $updateFields = [];
        $updateParams = [':id' => $userId];
        
        // Actualizar google_id si no lo tiene
        if (empty($existingUser['google_id']) && $googleId) {
            $updateFields[] = "google_id = :google_id";
            $updateParams[':google_id'] = $googleId;
        }
        
        // Actualizar foto de perfil si viene de Google y no tiene una
        if ($fotoPerfil && empty($existingUser['foto_perfil'])) {
            $updateFields[] = "foto_perfil = :foto_perfil";
            $updateParams[':foto_perfil'] = $fotoPerfil;
        }
        
        // Marcar email como verificado si Google lo confirma
        if ($emailVerificado && !$existingUser['es_verificado']) {
            $updateFields[] = "es_verificado = 1";
        }
        
        // Actualizar último acceso
        $updateFields[] = "ultimo_acceso_en = CURRENT_TIMESTAMP";
        
        if (!empty($updateFields)) {
            $updateQuery = "UPDATE usuarios SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute($updateParams);
        }
        
        // Recuperar usuario actualizado
        $user = [
            'id' => $existingUser['id'],
            'uuid' => $existingUser['uuid'],
            'nombre' => $existingUser['nombre'],
            'apellido' => $existingUser['apellido'],
            'email' => $existingUser['email'],
            'telefono' => $existingUser['telefono'],
            'tipo_usuario' => $existingUser['tipo_usuario'],
            'foto_perfil' => $fotoPerfil ?: $existingUser['foto_perfil'],
            'es_verificado' => true,
            'empresa_id' => $existingUser['empresa_id'],
            'requiere_telefono' => empty($existingUser['telefono'])
        ];
        
        // ========== EMPRESA STATUS CHECK ==========
        // If user is empresa type, check if empresa is approved
        if ($existingUser['tipo_usuario'] === 'empresa' && !empty($existingUser['empresa_id'])) {
            $empresaQuery = "SELECT estado FROM empresas_transporte WHERE id = ? LIMIT 1";
            $empresaStmt = $db->prepare($empresaQuery);
            $empresaStmt->execute([$existingUser['empresa_id']]);
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
        
    } else {
        // Nuevo usuario - crear registro
        $isNewUser = true;
        $uuid = uniqid('user_', true);
        
        // Generar contraseña aleatoria para usuarios de Google (no la usarán)
        $randomPassword = bin2hex(random_bytes(16));
        $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);
        
        $insertQuery = "INSERT INTO usuarios 
                        (uuid, nombre, apellido, email, hash_contrasena, tipo_usuario, 
                         foto_perfil, es_verificado, google_id, fecha_registro)
                        VALUES 
                        (:uuid, :nombre, :apellido, :email, :hash_contrasena, 'cliente',
                         :foto_perfil, 1, :google_id, CURRENT_TIMESTAMP)
                        RETURNING id";
        
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->execute([
            ':uuid' => $uuid,
            ':nombre' => $nombre,
            ':apellido' => $apellido,
            ':email' => $email,
            ':hash_contrasena' => $hashedPassword,
            ':foto_perfil' => $fotoPerfil,
            ':google_id' => $googleId
        ]);
        
        $result = $insertStmt->fetch(PDO::FETCH_ASSOC);
        $userId = $result['id'];
        
        $user = [
            'id' => $userId,
            'uuid' => $uuid,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => $email,
            'telefono' => null,
            'tipo_usuario' => 'cliente',
            'foto_perfil' => $fotoPerfil,
            'es_verificado' => true,
            'empresa_id' => null,
            'requiere_telefono' => true // Nuevo usuario siempre requiere teléfono
        ];
    }
    
    // Registrar dispositivo si se proporciona
    if (!empty($input['device_uuid'])) {
        registerDevice($db, $userId, $input['device_uuid']);
    }
    
    // Respuesta exitosa
    sendJsonResponse(true, $isNewUser ? 'Registro exitoso con Google' : 'Inicio de sesión exitoso con Google', [
        'user' => $user,
        'is_new_user' => $isNewUser,
        'requires_phone' => $user['requiere_telefono']
    ]);
    
} catch (PDOException $e) {
    error_log("Error de base de datos en Google callback: " . $e->getMessage());
    sendJsonResponse(false, 'Error de base de datos: ' . $e->getMessage());
} catch (Exception $e) {
    error_log("Error en Google callback: " . $e->getMessage());
    sendJsonResponse(false, 'Error: ' . $e->getMessage());
}

/**
 * Verifica un id_token de Google usando la API de Google
 * Acepta tokens de cliente web o móvil
 */
function verifyGoogleIdToken($idToken, $webClientId, $mobileClientId) {
    // URL de verificación de tokens de Google
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $tokenInfo = json_decode($response, true);
    
    // Verificar que el token sea para nuestra aplicación (web o móvil)
    $validClientIds = [$webClientId, $mobileClientId];
    if (!isset($tokenInfo['aud']) || !in_array($tokenInfo['aud'], $validClientIds)) {
        error_log("Google token audience mismatch. Expected one of: " . implode(', ', $validClientIds) . ", Got: " . ($tokenInfo['aud'] ?? 'null'));
        return null;
    }
    
    // Verificar que el token no esté expirado
    if (isset($tokenInfo['exp']) && $tokenInfo['exp'] < time()) {
        error_log("Google token expired");
        return null;
    }
    
    return $tokenInfo;
}

/**
 * Obtiene información del usuario de Google usando un access_token
 */
function getGoogleUserInfo($accessToken) {
    $url = 'https://www.googleapis.com/oauth2/v3/userinfo';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Error getting Google user info. HTTP Code: $httpCode, Response: $response");
        return null;
    }
    
    return json_decode($response, true);
}

/**
 * Registra un dispositivo como confiable para el usuario
 */
function registerDevice($db, $userId, $deviceUuid) {
    try {
        // Verificar si la tabla existe
        try {
            $db->query("SELECT 1 FROM user_devices LIMIT 1");
        } catch (Exception $e) {
            // Crear tabla si no existe
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
        
        // Insertar o actualizar dispositivo
        $stmt = $db->prepare("
            INSERT INTO user_devices (user_id, device_uuid, trusted, first_seen, last_seen)
            VALUES (:user_id, :device_uuid, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (user_id, device_uuid) 
            DO UPDATE SET trusted = 1, last_seen = CURRENT_TIMESTAMP, fail_attempts = 0
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':device_uuid' => $deviceUuid
        ]);
    } catch (Exception $e) {
        error_log("Error registering device: " . $e->getMessage());
    }
}
