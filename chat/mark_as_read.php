<?php
/**
 * API: Marcar mensajes como leídos
 * Endpoint: POST /chat/mark_as_read.php
 * 
 * Marca todos los mensajes de una conversación como leídos para un usuario.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $solicitudId = $input['solicitud_id'] ?? null;
    $usuarioId = $input['usuario_id'] ?? null;
    $mensajeId = $input['mensaje_id'] ?? null; // Opcional: marcar solo un mensaje
    
    if (!$solicitudId || !$usuarioId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'solicitud_id y usuario_id son requeridos'
        ]);
        exit();
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    if ($mensajeId) {
        // Marcar solo un mensaje específico
        $stmt = $db->prepare("
            UPDATE mensajes_chat 
            SET leido = true, leido_en = CURRENT_TIMESTAMP
            WHERE id = ? AND destinatario_id = ? AND leido = false
        ");
        $stmt->execute([$mensajeId, $usuarioId]);
    } else {
        // Marcar todos los mensajes no leídos de la conversación
        $stmt = $db->prepare("
            UPDATE mensajes_chat 
            SET leido = true, leido_en = CURRENT_TIMESTAMP
            WHERE solicitud_id = ? AND destinatario_id = ? AND leido = false
        ");
        $stmt->execute([$solicitudId, $usuarioId]);
    }
    
    $marcados = $stmt->rowCount();
    
    echo json_encode([
        'success' => true,
        'message' => 'Mensajes marcados como leídos',
        'mensajes_marcados' => $marcados
    ]);
    
} catch (PDOException $e) {
    error_log("mark_as_read.php PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de base de datos'
    ]);
} catch (Exception $e) {
    error_log("mark_as_read.php Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
