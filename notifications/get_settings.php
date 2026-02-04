<?php
/**
 * get_settings.php
 * Obtiene la configuración de notificaciones de un usuario
 * 
 * Parámetros GET:
 * - usuario_id: (requerido) ID del usuario
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;
    
    if ($usuario_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'usuario_id es requerido'
        ]);
        exit;
    }
    
    // Buscar configuración existente
    $query = "SELECT * FROM configuracion_notificaciones_usuario WHERE usuario_id = :usuario_id";
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        // Crear configuración por defecto
        $insertQuery = "
            INSERT INTO configuracion_notificaciones_usuario (usuario_id) 
            VALUES (:usuario_id) 
            RETURNING *
        ";
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
        $insertStmt->execute();
        $config = $insertStmt->fetch(PDO::FETCH_ASSOC);
    }
    
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
