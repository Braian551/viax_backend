<?php
/**
 * send_message.php
 * Envía un mensaje en un ticket existente
 * 
 * POST params:
 * - ticket_id: (required) ID del ticket
 * - usuario_id: (required) ID del usuario
 * - mensaje: (required) Contenido del mensaje
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $data = json_decode(file_get_contents("php://input"), true);
    
    $ticket_id = isset($data['ticket_id']) ? intval($data['ticket_id']) : 0;
    $usuario_id = isset($data['usuario_id']) ? intval($data['usuario_id']) : 0;
    $mensaje = isset($data['mensaje']) ? trim($data['mensaje']) : '';
    
    if ($ticket_id <= 0 || $usuario_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ticket_id y usuario_id son requeridos']);
        exit();
    }
    
    if (empty($mensaje)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'mensaje es requerido']);
        exit();
    }
    
    // Verificar que el ticket pertenece al usuario y está abierto
    $checkQuery = "SELECT id, estado FROM tickets_soporte WHERE id = :ticket_id AND usuario_id = :usuario_id";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindValue(':ticket_id', $ticket_id, PDO::PARAM_INT);
    $checkStmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $checkStmt->execute();
    $ticket = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Ticket no encontrado']);
        exit();
    }
    
    if ($ticket['estado'] === 'cerrado') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No puedes enviar mensajes a un ticket cerrado']);
        exit();
    }
    
    // Insertar mensaje
    $query = "
        INSERT INTO mensajes_ticket (ticket_id, remitente_id, es_agente, mensaje)
        VALUES (:ticket_id, :remitente_id, FALSE, :mensaje)
        RETURNING id, created_at
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':ticket_id', $ticket_id, PDO::PARAM_INT);
    $stmt->bindValue(':remitente_id', $usuario_id, PDO::PARAM_INT);
    $stmt->bindValue(':mensaje', $mensaje);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Actualizar ticket si estaba esperando usuario
    if ($ticket['estado'] === 'esperando_usuario') {
        $updateQuery = "UPDATE tickets_soporte SET estado = 'en_progreso', updated_at = CURRENT_TIMESTAMP WHERE id = :ticket_id";
        $conn->prepare($updateQuery)->execute([':ticket_id' => $ticket_id]);
    } else {
        // Solo actualizar timestamp
        $updateQuery = "UPDATE tickets_soporte SET updated_at = CURRENT_TIMESTAMP WHERE id = :ticket_id";
        $conn->prepare($updateQuery)->execute([':ticket_id' => $ticket_id]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Mensaje enviado',
        'mensaje' => [
            'id' => $result['id'],
            'mensaje' => $mensaje,
            'es_agente' => false,
            'created_at' => $result['created_at']
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al enviar mensaje: ' . $e->getMessage()
    ]);
}
