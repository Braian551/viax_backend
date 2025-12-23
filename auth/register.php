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
        INSERT INTO usuarios (uuid, nombre, apellido, email, telefono, hash_contrasena) 
        VALUES (?, ?, ?, ?, ?, ?)
    ";

    $stmt = $db->prepare($query);
    $stmt->execute([$uuid, $name, $lastName, $email, $phone, $password]);
    $userId = $db->lastInsertId();

    // Si el frontend envía device_uuid, registrar el dispositivo como confiable por primera vez
    if (!empty($input['device_uuid'])) {
        $deviceUuid = trim($input['device_uuid']);
        try {
            // Asegurar existencia de tabla user_devices
            $db->query("SELECT 1 FROM user_devices LIMIT 1");
        } catch (Exception $e) {
            // Crear tabla mínima si no existe (idempotente)
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
        
        // IMPORTANTE: Este es el primer dispositivo, así que no hay otros que invalidar
        // Insertar como dispositivo confiable
        $ins = $db->prepare('INSERT IGNORE INTO user_devices (user_id, device_uuid, trusted) VALUES (?, ?, 1)');
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