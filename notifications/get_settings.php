<?php
/**
 * get_settings.php
 * Obtiene la configuración de notificaciones de un usuario
 * 
 * Parámetros GET:
 */

$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../middleware/SessionAccess.php';

if (Request::method() !== 'GET') {
    header('Allow: GET');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $user = SessionAccess::requireUser($conn);
    $usuario_id = (int)$user['id'];
    SessionAccess::requireOwner($_GET, $usuario_id);
    
    
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
        'error' => 'Error de base de datos'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'No se pudo gestionar la notificación'
    ]);
}
