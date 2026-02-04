<?php
/**
 * get_ticket_messages.php
 * Obtiene los mensajes de un ticket
 * 
 * GET params:
 * - ticket_id: (required) ID del ticket
 * - usuario_id: (required) ID del usuario (para validar acceso)
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
    
    $ticket_id = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;
    $usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;
    
    if ($ticket_id <= 0 || $usuario_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ticket_id y usuario_id son requeridos']);
        exit();
    }
    
    // Verificar que el ticket pertenece al usuario
    $checkQuery = "SELECT id, numero_ticket, asunto, estado, prioridad, created_at FROM tickets_soporte WHERE id = :ticket_id AND usuario_id = :usuario_id";
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
    
    // Obtener mensajes
    $query = "
        SELECT 
            m.id,
            m.mensaje,
            m.es_agente,
            m.adjuntos,
            m.created_at,
            u.nombre as remitente_nombre
        FROM mensajes_ticket m
        LEFT JOIN usuarios u ON m.remitente_id = u.id
        WHERE m.ticket_id = :ticket_id
        ORDER BY m.created_at ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':ticket_id', $ticket_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear adjuntos
    foreach ($mensajes as &$msg) {
        $msg['es_agente'] = (bool) $msg['es_agente'];
        $msg['adjuntos'] = json_decode($msg['adjuntos'], true) ?? [];
    }
    
    // Marcar mensajes de agente como leídos
    $markReadQuery = "
        UPDATE mensajes_ticket 
        SET leido = TRUE, leido_en = CURRENT_TIMESTAMP 
        WHERE ticket_id = :ticket_id AND es_agente = TRUE AND leido = FALSE
    ";
    $conn->prepare($markReadQuery)->execute([':ticket_id' => $ticket_id]);
    
    echo json_encode([
        'success' => true,
        'ticket' => $ticket,
        'mensajes' => $mensajes
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
