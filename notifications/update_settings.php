<?php
/**
 * update_settings.php
 * Actualiza la configuración de notificaciones de un usuario
 * 
 * POST Body (JSON):
 * - usuario_id: (requerido) ID del usuario
 * - push_enabled: Notificaciones push habilitadas
 * - email_enabled: Notificaciones por email habilitadas
 * - sms_enabled: Notificaciones SMS habilitadas
 * - notif_viajes: Notificaciones de viajes
 * - notif_pagos: Notificaciones de pagos
 * - notif_promociones: Notificaciones de promociones
 * - notif_sistema: Notificaciones del sistema
 * - notif_chat: Notificaciones de chat
 * - horario_silencioso_inicio: Inicio del horario silencioso (HH:MM)
 * - horario_silencioso_fin: Fin del horario silencioso (HH:MM)
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener datos del body
    $input = json_decode(file_get_contents('php://input'), true);
    
    $usuario_id = isset($input['usuario_id']) ? intval($input['usuario_id']) : 0;
    
    if ($usuario_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'usuario_id es requerido'
        ]);
        exit;
    }
    
    // Verificar si existe configuración
    $checkQuery = "SELECT id FROM configuracion_notificaciones_usuario WHERE usuario_id = :usuario_id";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $checkStmt->execute();
    $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    // Construir campos a actualizar
    $fields = [];
    $params = [':usuario_id' => $usuario_id];
    
    $boolFields = [
        'push_enabled', 'email_enabled', 'sms_enabled',
        'notif_viajes', 'notif_pagos', 'notif_promociones',
        'notif_sistema', 'notif_chat'
    ];
    
    foreach ($boolFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $input[$field] ? true : false;
        }
    }
    
    // Campos de horario silencioso
    if (array_key_exists('horario_silencioso_inicio', $input)) {
        $fields[] = "horario_silencioso_inicio = :horario_inicio";
        $params[':horario_inicio'] = $input['horario_silencioso_inicio'];
    }
    
    if (array_key_exists('horario_silencioso_fin', $input)) {
        $fields[] = "horario_silencioso_fin = :horario_fin";
        $params[':horario_fin'] = $input['horario_silencioso_fin'];
    }
    
    if (empty($fields)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'No se especificaron campos para actualizar'
        ]);
        exit;
    }
    
    if ($exists) {
        // Actualizar
        $query = "UPDATE configuracion_notificaciones_usuario SET " . 
                 implode(', ', $fields) . 
                 " WHERE usuario_id = :usuario_id RETURNING *";
    } else {
        // Insertar con valores por defecto y los campos especificados
        $allFields = array_merge(
            ['usuario_id'],
            array_map(fn($f) => explode(' = ', $f)[0], $fields)
        );
        $allParams = array_merge(
            [':usuario_id'],
            array_map(fn($f) => ':' . explode(' = ', $f)[0], $fields)
        );
        
        $query = "INSERT INTO configuracion_notificaciones_usuario (" . 
                 implode(', ', $allFields) . ") VALUES (" .
                 implode(', ', $allParams) . ") RETURNING *";
    }
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        if (is_bool($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_BOOL);
        } elseif (is_int($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Formatear respuesta
    $settings = [
        'push_enabled' => (bool) $config['push_enabled'],
        'email_enabled' => (bool) $config['email_enabled'],
        'sms_enabled' => (bool) $config['sms_enabled'],
        'notif_viajes' => (bool) $config['notif_viajes'],
        'notif_pagos' => (bool) $config['notif_pagos'],
        'notif_promociones' => (bool) $config['notif_promociones'],
        'notif_sistema' => (bool) $config['notif_sistema'],
        'notif_chat' => (bool) $config['notif_chat'],
        'horario_silencioso_inicio' => $config['horario_silencioso_inicio'],
        'horario_silencioso_fin' => $config['horario_silencioso_fin'],
    ];
    
    echo json_encode([
        'success' => true,
        'message' => 'Configuración actualizada exitosamente',
        'settings' => $settings
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos',
        'details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
