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
require_once __DIR__ . '/_support_auth.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $ticket_id = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;
    $actor_id = supportResolveActorId($_GET);

    if ($ticket_id <= 0 || $actor_id <= 0) {
        supportJsonError('ticket_id y usuario_id/agente_id son requeridos', 400);
    }

    $actor = supportGetActor($conn, $actor_id);
    if (!$actor) {
        supportJsonError('Actor no encontrado', 404);
    }

    // Verificar que el actor tenga permiso sobre el ticket
    $checkQuery = "\n        SELECT id, numero_ticket, asunto, estado, prioridad, created_at, usuario_id, agente_id\n        FROM tickets_soporte\n        WHERE id = :ticket_id\n    ";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindValue(':ticket_id', $ticket_id, PDO::PARAM_INT);
    $checkStmt->execute();
    $ticket = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        supportJsonError('Ticket no encontrado', 404);
    }

    $isOwner = ((int) $ticket['usuario_id'] === $actor_id);
    $isAgent = (bool) $actor['es_agente_soporte'];
    if (!$isOwner && !$isAgent) {
        supportJsonError('No tienes permisos para ver este ticket', 403);
    }
    
    // Obtener mensajes
    $query = "
        SELECT 
            m.id,
            m.mensaje,
            m.es_agente,
            m.adjuntos,
            m.leido,
            m.created_at,
            u.nombre as remitente_nombre,
            u.apellido as remitente_apellido,
            u.tipo_usuario as remitente_tipo
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
        $msg['leido'] = (bool) $msg['leido'];
        $msg['adjuntos'] = json_decode($msg['adjuntos'], true) ?? [];
    }
    
    // Marcar como leidos los mensajes del otro lado de la conversacion.
    $markFromAgent = !$isAgent;
    $markReadQuery = "
        UPDATE mensajes_ticket 
        SET leido = TRUE, leido_en = CURRENT_TIMESTAMP 
        WHERE ticket_id = :ticket_id AND es_agente = :from_agent AND leido = FALSE
    ";
    $markStmt = $conn->prepare($markReadQuery);
    $markStmt->bindValue(':ticket_id', $ticket_id, PDO::PARAM_INT);
    $markStmt->bindValue(':from_agent', $markFromAgent, PDO::PARAM_BOOL);
    $markStmt->execute();
    
    echo json_encode([
        'success' => true,
        'ticket' => $ticket,
        'actor' => [
            'id' => (int) $actor['id'],
            'tipo_usuario' => $actor['tipo_usuario'],
            'es_agente_soporte' => $isAgent,
        ],
        'mensajes' => $mensajes
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
