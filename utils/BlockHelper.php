<?php
/**
 * Utilidades de bloqueo entre usuarios (cliente/conductor).
 */

class BlockHelper
{
    public static function getBlockState(PDO $db, int $actorId, int $otherUserId): array
    {
        $stmt = $db->prepare(
            "SELECT
                EXISTS(
                    SELECT 1 FROM blocked_users
                    WHERE user_id = ? AND blocked_user_id = ? AND active = true
                ) AS blocked_by_me,
                EXISTS(
                    SELECT 1 FROM blocked_users
                    WHERE user_id = ? AND blocked_user_id = ? AND active = true
                ) AS blocked_me"
        );
        $stmt->execute([$actorId, $otherUserId, $otherUserId, $actorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $blockedByMe = filter_var($row['blocked_by_me'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $blockedMe = filter_var($row['blocked_me'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'blocked_by_me' => $blockedByMe,
            'blocked_me' => $blockedMe,
            'either_blocked' => $blockedByMe || $blockedMe,
        ];
    }

    public static function hasSharedTrip(PDO $db, int $userAId, int $userBId, ?int $solicitudId = null): bool
    {
        $sql = "
            SELECT 1
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON ac.solicitud_id = s.id
            WHERE (
                (s.cliente_id = :a AND ac.conductor_id = :b)
                OR
                (s.cliente_id = :b AND ac.conductor_id = :a)
            )
        ";

        $params = [':a' => $userAId, ':b' => $userBId];

        if ($solicitudId !== null && $solicitudId > 0) {
            $sql .= " AND s.id = :solicitud_id";
            $params[':solicitud_id'] = $solicitudId;
        }

        $sql .= " LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    public static function hasActiveTrip(PDO $db, int $userAId, int $userBId): bool
    {
        $stmt = $db->prepare(
            "
            SELECT 1
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON ac.solicitud_id = s.id
            WHERE (
                (s.cliente_id = :a AND ac.conductor_id = :b)
                OR
                (s.cliente_id = :b AND ac.conductor_id = :a)
            )
              AND s.estado IN ('aceptada', 'conductor_llego', 'en_curso')
            LIMIT 1
            "
        );
        $stmt->execute([':a' => $userAId, ':b' => $userBId]);
        return (bool)$stmt->fetchColumn();
    }
}
