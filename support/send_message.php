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

$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
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
require_once __DIR__ . '/_support_auth.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $data = json_decode(file_get_contents("php://input"), true);
    
    $ticket_id = isset($data['ticket_id']) ? intval($data['ticket_id']) : 0;
    $usuario_id = supportResolveActorId($data);
    $mensaje = isset($data['mensaje']) ? trim($data['mensaje']) : '';
    
    if ($ticket_id <= 0 || $usuario_id <= 0) {
        supportJsonError('ticket_id y usuario_id/agente_id son requeridos', 400);
    }
    
    if (empty($mensaje)) {
        supportJsonError('mensaje es requerido', 400);
    }

    $actor = supportGetActor($conn, $usuario_id);
    if (!$actor) {
        supportJsonError('Actor no encontrado', 404);
    }
    $isAgent = (bool) $actor['es_agente_soporte'];
    
    // Verificar que el ticket pertenece al usuario y está abierto
    $checkQuery = "SELECT id, numero_ticket, asunto, estado, usuario_id, agente_id, prioridad FROM tickets_soporte WHERE id = :ticket_id";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindValue(':ticket_id', $ticket_id, PDO::PARAM_INT);
    $checkStmt->execute();
    $ticket = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        supportJsonError('Ticket no encontrado', 404);
    }

    $isOwner = ((int) $ticket['usuario_id'] === $usuario_id);
    if (!$isOwner && !$isAgent) {
        supportJsonError('No tienes permisos para responder este ticket', 403);
    }
    
    // Insertar mensaje
    $query = "
        INSERT INTO mensajes_ticket (ticket_id, remitente_id, es_agente, mensaje)
        VALUES (:ticket_id, :remitente_id, :es_agente, :mensaje)
        RETURNING id, created_at
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':ticket_id', $ticket_id, PDO::PARAM_INT);
    $stmt->bindValue(':remitente_id', $usuario_id, PDO::PARAM_INT);
    $stmt->bindValue(':es_agente', $isAgent, PDO::PARAM_BOOL);
    $stmt->bindValue(':mensaje', $mensaje);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $estadoAnterior = $ticket['estado'];
    $estadoNuevo = $estadoAnterior;
    if ($isAgent) {
        if (in_array($estadoAnterior, ['abierto', 'en_progreso', 'resuelto', 'cerrado'], true)) {
            $estadoNuevo = 'esperando_usuario';
        }
    } else {
        if (in_array($estadoAnterior, ['esperando_usuario', 'resuelto', 'cerrado'], true)) {
            $estadoNuevo = 'en_progreso';
        }
    }

    $updates = ['updated_at = CURRENT_TIMESTAMP'];
    if ($estadoNuevo !== $estadoAnterior) {
        $updates[] = 'estado = :estado';
    }
    if ($isAgent && empty($ticket['agente_id'])) {
        $updates[] = 'agente_id = :agente_id';
    }
    $updateQuery = 'UPDATE tickets_soporte SET ' . implode(', ', $updates) . ' WHERE id = :ticket_id';
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bindValue(':ticket_id', $ticket_id, PDO::PARAM_INT);
    if ($estadoNuevo !== $estadoAnterior) {
        $updateStmt->bindValue(':estado', $estadoNuevo);
    }
    if ($isAgent && empty($ticket['agente_id'])) {
        $updateStmt->bindValue(':agente_id', $usuario_id, PDO::PARAM_INT);
    }
    $updateStmt->execute();

    supportInsertTicketLog(
        $conn,
        $ticket_id,
        $usuario_id,
        $isAgent ? 'mensaje_agente' : 'mensaje_usuario',
        [
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estadoNuevo,
            'prioridad_anterior' => $ticket['prioridad'],
            'prioridad_nueva' => $ticket['prioridad'],
            'metadata' => ['mensaje_id' => (int) $result['id']],
        ]
    );

    $actorName = supportActorDisplayName($actor);
    $preview = strlen($mensaje) > 120 ? substr($mensaje, 0, 117) . '...' : $mensaje;
    if ($isAgent) {
        supportNotifyUser(
            (int) $ticket['usuario_id'],
            'Respuesta en tu ticket de soporte',
            $actorName . ' respondio ' . ($ticket['numero_ticket'] ?? ('#' . $ticket_id)) . ': ' . $preview,
            (int) $ticket_id,
            [
                'module' => 'support',
                'action' => 'agent_message',
                'ticket_id' => (int) $ticket_id,
                'numero_ticket' => $ticket['numero_ticket'] ?? null,
                'from_agent_id' => $usuario_id,
            ],
            'chat_message'
        );
    } else {
        $assignedAgentId = (int) ($ticket['agente_id'] ?? 0);
        if ($assignedAgentId > 0) {
            supportNotifyUser(
                $assignedAgentId,
                'Nuevo mensaje de usuario en soporte',
                $actorName . ' escribio en ' . ($ticket['numero_ticket'] ?? ('#' . $ticket_id)) . ': ' . $preview,
                (int) $ticket_id,
                [
                    'module' => 'support',
                    'action' => 'user_message',
                    'ticket_id' => (int) $ticket_id,
                    'numero_ticket' => $ticket['numero_ticket'] ?? null,
                    'from_user_id' => $usuario_id,
                ],
                'chat_message'
            );
        } else {
            $agentsToNotify = supportAgentIds($conn, $usuario_id);
            foreach ($agentsToNotify as $agentId) {
                supportNotifyUser(
                    $agentId,
                    'Nuevo mensaje de usuario en soporte',
                    $actorName . ' escribio en ' . ($ticket['numero_ticket'] ?? ('#' . $ticket_id)) . ': ' . $preview,
                    (int) $ticket_id,
                    [
                        'module' => 'support',
                        'action' => 'user_message',
                        'ticket_id' => (int) $ticket_id,
                        'numero_ticket' => $ticket['numero_ticket'] ?? null,
                        'from_user_id' => $usuario_id,
                    ],
                    'chat_message'
                );
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Mensaje enviado',
        'mensaje' => [
            'id' => $result['id'],
            'mensaje' => $mensaje,
            'es_agente' => $isAgent,
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
