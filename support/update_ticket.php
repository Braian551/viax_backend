<?php
/**
 * update_ticket.php
 * Permite a agentes de soporte (admin/soporte_tecnico) gestionar estado,
 * prioridad y asignacion de tickets.
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
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/_support_auth.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        supportJsonError('Payload JSON invalido', 400);
    }

    $ticketId = isset($data['ticket_id']) ? (int) $data['ticket_id'] : 0;
    $actorId = supportResolveActorId($data);
    $newStatus = isset($data['estado']) ? trim((string) $data['estado']) : null;
    $newPriority = isset($data['prioridad']) ? trim((string) $data['prioridad']) : null;
    $assignedTo = array_key_exists('asignado_a', $data) ? $data['asignado_a'] : null;

    if ($ticketId <= 0 || $actorId <= 0) {
        supportJsonError('ticket_id y agente_id son requeridos', 400);
    }

    $actor = supportGetActor($conn, $actorId);
    if (!$actor || !$actor['es_agente_soporte']) {
        supportJsonError('Solo agentes de soporte pueden gestionar tickets', 403);
    }

    $statusAllowed = ['abierto', 'en_progreso', 'esperando_usuario', 'resuelto', 'cerrado'];
    $priorityAllowed = ['baja', 'normal', 'alta', 'urgente'];

    if ($newStatus !== null && !in_array($newStatus, $statusAllowed, true)) {
        supportJsonError('Estado no valido', 400);
    }

    if ($newPriority !== null && !in_array($newPriority, $priorityAllowed, true)) {
        supportJsonError('Prioridad no valida', 400);
    }

    $ticketStmt = $conn->prepare('SELECT id, numero_ticket, estado, prioridad, agente_id, usuario_id FROM tickets_soporte WHERE id = :id LIMIT 1');
    $ticketStmt->bindValue(':id', $ticketId, PDO::PARAM_INT);
    $ticketStmt->execute();
    $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        supportJsonError('Ticket no encontrado', 404);
    }

    $updates = [];
    $params = [':ticket_id' => $ticketId];

    if ($newStatus !== null && $newStatus !== $ticket['estado']) {
        $updates[] = 'estado = :estado';
        $params[':estado'] = $newStatus;

        if ($newStatus === 'resuelto') {
            $updates[] = 'resuelto_en = CURRENT_TIMESTAMP';
        }

        if ($newStatus === 'cerrado') {
            $updates[] = 'cerrado_en = CURRENT_TIMESTAMP';
        }
    }

    if ($newPriority !== null && $newPriority !== $ticket['prioridad']) {
        $updates[] = 'prioridad = :prioridad';
        $params[':prioridad'] = $newPriority;
    }

    if ($assignedTo !== null) {
        if ($assignedTo === '' || $assignedTo === 'null') {
            if (!empty($ticket['agente_id'])) {
                $updates[] = 'agente_id = NULL';
            }
        } else {
            $assignedToId = (int) $assignedTo;
            $assignedUser = supportGetActor($conn, $assignedToId);
            if (!$assignedUser || !$assignedUser['es_agente_soporte']) {
                supportJsonError('El usuario asignado debe ser un agente de soporte', 400);
            }
            if ((int) ($ticket['agente_id'] ?? 0) !== $assignedToId) {
                $updates[] = 'agente_id = :agente_id';
                $params[':agente_id'] = $assignedToId;
            }
        }
    }

    if (empty($updates)) {
        echo json_encode([
            'success' => true,
            'message' => 'No hubo cambios para aplicar',
            'ticket' => $ticket,
        ]);
        exit();
    }

    $updates[] = 'updated_at = CURRENT_TIMESTAMP';

    $query = 'UPDATE tickets_soporte SET ' . implode(', ', $updates) . ' WHERE id = :ticket_id';
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        if ($key === ':agente_id' || $key === ':ticket_id') {
            $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();

    $resultStmt = $conn->prepare('SELECT id, numero_ticket, estado, prioridad, agente_id, updated_at FROM tickets_soporte WHERE id = :id');
    $resultStmt->bindValue(':id', $ticketId, PDO::PARAM_INT);
    $resultStmt->execute();
    $updated = $resultStmt->fetch(PDO::FETCH_ASSOC);

    supportInsertTicketLog(
        $conn,
        $ticketId,
        $actorId,
        'actualizacion_ticket',
        [
            'estado_anterior' => $ticket['estado'],
            'estado_nuevo' => $updated['estado'] ?? $ticket['estado'],
            'prioridad_anterior' => $ticket['prioridad'],
            'prioridad_nueva' => $updated['prioridad'] ?? $ticket['prioridad'],
            'metadata' => [
                'agente_anterior' => $ticket['agente_id'],
                'agente_nuevo' => $updated['agente_id'] ?? null,
            ],
        ]
    );

    $actorName = supportActorDisplayName($actor);
    $statusChanged = ($ticket['estado'] ?? null) !== ($updated['estado'] ?? null);
    $priorityChanged = ($ticket['prioridad'] ?? null) !== ($updated['prioridad'] ?? null);
    $agentChanged = ((int) ($ticket['agente_id'] ?? 0)) !== ((int) ($updated['agente_id'] ?? 0));

    if ($statusChanged || $priorityChanged || $agentChanged) {
        $summaryParts = [];
        if ($statusChanged) {
            $summaryParts[] = 'estado: ' . ($ticket['estado'] ?? '-') . ' -> ' . ($updated['estado'] ?? '-');
        }
        if ($priorityChanged) {
            $summaryParts[] = 'prioridad: ' . ($ticket['prioridad'] ?? '-') . ' -> ' . ($updated['prioridad'] ?? '-');
        }
        if ($agentChanged) {
            $summaryParts[] = 'asignacion actualizada';
        }

        supportNotifyUser(
            (int) $ticket['usuario_id'],
            'Actualizacion de ticket de soporte',
            $actorName . ' actualizo ' . ($updated['numero_ticket'] ?? ('#' . $ticketId)) . ' (' . implode(', ', $summaryParts) . ')',
            (int) $ticketId,
            [
                'module' => 'support',
                'action' => 'ticket_updated',
                'ticket_id' => (int) $ticketId,
                'numero_ticket' => $updated['numero_ticket'] ?? null,
                'estado' => $updated['estado'] ?? null,
                'prioridad' => $updated['prioridad'] ?? null,
                'agente_id' => $updated['agente_id'] ?? null,
            ]
        );
    }

    $newAssignedAgentId = (int) ($updated['agente_id'] ?? 0);
    if ($agentChanged && $newAssignedAgentId > 0 && $newAssignedAgentId !== $actorId) {
        supportNotifyUser(
            $newAssignedAgentId,
            'Ticket asignado a tu bandeja',
            $actorName . ' te asigno el ticket ' . ($updated['numero_ticket'] ?? ('#' . $ticketId)) . '.',
            (int) $ticketId,
            [
                'module' => 'support',
                'action' => 'ticket_assigned',
                'ticket_id' => (int) $ticketId,
                'numero_ticket' => $updated['numero_ticket'] ?? null,
            ]
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Ticket actualizado correctamente',
        'ticket' => $updated,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al actualizar ticket: ' . $e->getMessage(),
    ]);
}
