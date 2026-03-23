<?php

require_once __DIR__ . '/../config/database.php';

/**
 * Reglas de acceso para consulta de datos financieros sensibles.
 */
class FinancialAccessControl
{
    public static function getActorById(PDO $db, int $actorUserId): ?array
    {
        $stmt = $db->prepare('SELECT id, tipo_usuario, empresa_id FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $actorUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'tipo_usuario' => strtolower((string) ($row['tipo_usuario'] ?? '')),
            'empresa_id' => isset($row['empresa_id']) ? (int) $row['empresa_id'] : null,
        ];
    }

    public static function canViewAdminBank(array $actor): bool
    {
        $role = $actor['tipo_usuario'] ?? '';
        return in_array($role, ['admin', 'administrador', 'empresa'], true);
    }

    public static function canViewEmpresaBank(array $actor, int $empresaId): bool
    {
        $role = $actor['tipo_usuario'] ?? '';
        $actorEmpresaId = (int) ($actor['empresa_id'] ?? 0);

        if (in_array($role, ['admin', 'administrador'], true)) {
            return true;
        }

        if (in_array($role, ['empresa', 'conductor'], true) && $actorEmpresaId > 0) {
            return $actorEmpresaId === $empresaId;
        }

        return false;
    }

    public static function audit(
        PDO $db,
        int $actorUserId,
        string $actorRole,
        string $resourceType,
        ?int $resourceId,
        bool $granted,
        string $reason
    ): void {
        try {
            $stmt = $db->prepare("INSERT INTO financial_access_audit_logs
                (actor_user_id, actor_role, resource_type, resource_id, granted, reason, ip_address, user_agent, accessed_at)
                VALUES
                (:actor_user_id, :actor_role, :resource_type, :resource_id, :granted, :reason, :ip_address, :user_agent, NOW())");

            $stmt->execute([
                ':actor_user_id' => $actorUserId,
                ':actor_role' => $actorRole,
                ':resource_type' => $resourceType,
                ':resource_id' => $resourceId,
                ':granted' => $granted,
                ':reason' => $reason,
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('[FinancialAccessControl] Error registrando auditoría: ' . $e->getMessage());
        }
    }
}
