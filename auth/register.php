<?php
// Incluir configuración
require_once '../config/config.php';

try {
    // Obtener datos del request
    $input = getJsonInput();

    // Campos requeridos básicos
    $requiredFields = ['email', 'password', 'name', 'lastName', 'phone'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            sendJsonResponse(false, "Campo $field es requerido");
        }
    }

    $email = filter_var($input['email'], FILTER_VALIDATE_EMAIL);
    $password = password_hash($input['password'], PASSWORD_DEFAULT);
    $name = trim($input['name']);
    $lastName = trim($input['lastName']);
    $phone = trim($input['phone']);

    // Validar tipo de usuario (cliente o empresa)
    $tipoUsuario = 'cliente';
    
    // Si se especifica un rol, validarlo
    if (isset($input['role']) && !empty($input['role'])) {
        $requestedRole = trim($input['role']);
        if (in_array($requestedRole, ['empresa', 'cliente'])) {
            $tipoUsuario = $requestedRole;
        }
    } else if (isset($input['tipo_usuario']) && !empty($input['tipo_usuario'])) {
        $requestedRole = trim($input['tipo_usuario']);
        if (in_array($requestedRole, ['empresa', 'cliente'])) {
            $tipoUsuario = $requestedRole;
        }
    }

    if (!$email) {
        sendJsonResponse(false, 'Email inválido');
    }

    // Campos opcionales de ubicación que puede enviar el frontend al confirmar dirección
    $direccion = isset($input['address']) ? trim($input['address']) : null;
    // aceptar variantes 'lat'/'lng' o 'latitude'/'longitude' usadas por el frontend
    $latitud = null;
    $longitud = null;
    if (isset($input['lat'])) { $latitud = $input['lat']; }
    if (isset($input['lng'])) { $longitud = $input['lng']; }
    if (isset($input['latitude'])) { $latitud = $input['latitude']; }
    if (isset($input['longitude'])) { $longitud = $input['longitude']; }
    $ciudad = isset($input['city']) ? $input['city'] : null;
    $departamento = isset($input['state']) ? $input['state'] : null;
    $pais = isset($input['country']) ? $input['country'] : 'Colombia';
    $codigo_postal = isset($input['postal_code']) ? $input['postal_code'] : null;
    $es_principal = isset($input['is_primary']) ? ($input['is_primary'] ? 1 : 0) : 1;

    // Conectar a la base de datos
    $database = new Database();
    $db = $database->getConnection();

    // Verificar si el usuario ya existe
    $query = "SELECT id FROM usuarios WHERE email = ? OR telefono = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$email, $phone]);

    if ($stmt->fetch()) {
        sendJsonResponse(false, 'El usuario ya existe');
    }

    // Insertar nuevo usuario
    $uuid = uniqid('user_', true);
    $query = "
        INSERT INTO usuarios (uuid, nombre, apellido, email, telefono, hash_contrasena, tipo_usuario) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $db->prepare($query);
    $stmt->execute([$uuid, $name, $lastName, $email, $phone, $password, $tipoUsuario]);
    $userId = $db->lastInsertId();

    // Si el frontend envía device_uuid, registrar el dispositivo como confiable por primera vez
    if (!empty($input['device_uuid'])) {
        $deviceUuid = trim($input['device_uuid']);
        try {
            // Asegurar existencia de tabla user_devices
            $db->query("SELECT 1 FROM user_devices LIMIT 1");
        } catch (Exception $e) {
            // Crear tabla mínima si no existe (sintaxis PostgreSQL)
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
        
        // IMPORTANTE: Este es el primer dispositivo, así que no hay otros que invalidar
        // Insertar como dispositivo confiable (sintaxis PostgreSQL)
        $ins = $db->prepare('INSERT INTO user_devices (user_id, device_uuid, trusted) VALUES (?, ?, 1) ON CONFLICT (user_id, device_uuid) DO NOTHING');
        $ins->execute([$userId, $deviceUuid]);
    }

    // Si se envió alguna información de ubicación, insertar en ubicaciones_usuario
    if ($direccion || $latitud || $longitud) {
        $query = "
            INSERT INTO ubicaciones_usuario (usuario_id, latitud, longitud, direccion, ciudad, departamento, pais, codigo_postal, es_principal, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";

        $stmt = $db->prepare($query);
        // Bind de valores, permitiendo nulls para lat/lng y demás
        $stmt->execute([
            $userId,
            $latitud,
            $longitud,
            $direccion,
            $ciudad,
            $departamento,
            $pais,
            $codigo_postal,
            $es_principal
        ]);
    }

    // Preparar respuesta con datos del usuario y dirección principal (si existe)
    $user = [
        'id' => $userId,
        'uuid' => $uuid,
        'nombre' => $name,
        'apellido' => $lastName,
        'email' => $email,
        'telefono' => $phone,
    ];

    // Obtener la ubicación principal si existe
    $query = "SELECT id, latitud, longitud, direccion, ciudad, departamento, pais, codigo_postal, es_principal FROM ubicaciones_usuario WHERE usuario_id = ? ORDER BY es_principal DESC, creado_en DESC LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([$userId]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);

    // Enviar correo de bienvenida (solo si es cliente o si se desea para todos)
    // Como la función es sendClientWelcomeEmail, la usamos aquí.
    if (!empty($email) && !empty($name)) {
        try {
            require_once '../utils/Mailer.php';
            // Ejecutar en "segundo plano" idealmente, pero aquí lo haremos directo
            // Opcional: Verificar si Mailer existe para evitar error fatal si falta el archivo
            if (class_exists('Mailer')) {
                 Mailer::sendClientWelcomeEmail($email, $name);
            }
        } catch (Exception $e) {
            // No interrumpir el registro si falla el correo
            error_log("Error enviando email de bienvenida: " . $e->getMessage());
        }
    }

    sendJsonResponse(true, 'Usuario registrado correctamente', [
        'user' => $user,
        'location' => $location,
        'device_registered' => !empty($input['device_uuid'])
    ]);

} catch (Exception $e) {
    http_response_code(500);
    sendJsonResponse(false, 'Error: ' . $e->getMessage());
}
?>