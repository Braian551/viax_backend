<?php

require_once __DIR__ . '/../utils/NotificationHelper.php';

function supportJsonError(string $message, int $httpStatus = 400): void
{
    http_response_code($httpStatus);
    echo json_encode(['success' => false, 'error' => $message]);
    exit();
}

function supportIsAgentRole(?string $role): bool
{
    return in_array((string) $role, ['administrador', 'soporte_tecnico'], true);
}

function supportResolveActorId(array $payload): int
{
    if (!empty($payload['agente_id'])) {
        return (int) $payload['agente_id'];
    }

    return !empty($payload['usuario_id']) ? (int) $payload['usuario_id'] : 0;
}

function supportGetActor(PDO $conn, int $actorId): ?array
{
    if ($actorId <= 0) {
        return null;
    }

    $stmt = $conn->prepare('SELECT id, tipo_usuario, nombre, apellido, email FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $actorId, PDO::PARAM_INT);
    $stmt->execute();

    $actor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$actor) {
        return null;
    }

    $actor['es_agente_soporte'] = supportIsAgentRole($actor['tipo_usuario']);
    return $actor;
}

function supportInsertTicketLog(PDO $conn, int $ticketId, ?int $actorId, string $action, array $changes = []): void
{
    try {
        $stmt = $conn->prepare(
            'INSERT INTO ticket_soporte_logs (
                ticket_id,
                actor_id,
                accion,
                estado_anterior,
                estado_nuevo,
                prioridad_anterior,
                prioridad_nueva,
                metadata
            ) VALUES (
                :ticket_id,
                :actor_id,
                :accion,
                :estado_anterior,
                :estado_nuevo,
                :prioridad_anterior,
                :prioridad_nueva,
                :metadata::jsonb
            )'
        );

        $stmt->bindValue(':ticket_id', $ticketId, PDO::PARAM_INT);
        $stmt->bindValue(':actor_id', $actorId ?: null, $actorId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':accion', $action);
        $stmt->bindValue(':estado_anterior', $changes['estado_anterior'] ?? null);
        $stmt->bindValue(':estado_nuevo', $changes['estado_nuevo'] ?? null);
        $stmt->bindValue(':prioridad_anterior', $changes['prioridad_anterior'] ?? null);
        $stmt->bindValue(':prioridad_nueva', $changes['prioridad_nueva'] ?? null);
        $stmt->bindValue(':metadata', json_encode($changes['metadata'] ?? new stdClass(), JSON_UNESCAPED_UNICODE));
        $stmt->execute();
    } catch (Throwable $e) {
        // No romper la operacion principal por trazabilidad.
    }
}

function supportActorDisplayName(array $actor): string
{
    $fullName = trim(((string) ($actor['nombre'] ?? '')) . ' ' . ((string) ($actor['apellido'] ?? '')));
    if ($fullName !== '') {
        return $fullName;
    }

    return (string) ($actor['email'] ?? 'Equipo de soporte');
}

function supportAgentIds(PDO $conn, ?int $excludeId = null): array
{
    $query = "
        SELECT id
        FROM usuarios
        WHERE tipo_usuario IN ('administrador', 'soporte_tecnico')
          AND es_activo = 1
    ";

    if ($excludeId !== null && $excludeId > 0) {
        $query .= ' AND id <> :exclude_id';
    }

    $stmt = $conn->prepare($query);
    if ($excludeId !== null && $excludeId > 0) {
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
    }
    $stmt->execute();

    return array_map(static fn(array $row): int => (int) $row['id'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function supportNotifyUser(
    int $userId,
    string $title,
    string $message,
    int $ticketId,
    array $data = [],
    string $type = 'system'
): void {
    if ($userId <= 0) {
        return;
    }

    try {
        NotificationHelper::crear(
            $userId,
            $type,
            $title,
            $message,
            'ticket_soporte',
            $ticketId,
            $data
        );
    } catch (Throwable $e) {
        error_log('supportNotifyUser error: ' . $e->getMessage());
    }
}
