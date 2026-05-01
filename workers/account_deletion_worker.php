<?php
/**
 * Worker diario para materializar la eliminación irreversible de cuentas.
 *
 * Flujo:
 * 1) Toma cuentas en status pending_deletion cuyo plazo de 15 días ya venció.
 * 2) Revoca artefactos de sesión y códigos de verificación activos.
 * 3) Anonimiza datos personales mínimos y marca status=deleted.
 */

require_once __DIR__ . '/../config/app.php';

const ACCOUNT_DELETION_WORKER_INTERVAL_SEC = 86400;

function anonymizedEmailForUser(int $userId): string
{
    return sprintf('deleted_%d_%d@deleted.viax', $userId, time());
}

function processPendingDeletionUsers(PDO $db): int
{
    $selectStmt = $db->prepare(
        "SELECT id, email
         FROM usuarios
         WHERE status IN ('inactive', 'pending_deletion')
           AND deletion_scheduled_at IS NOT NULL
           AND deletion_scheduled_at <= NOW()
         ORDER BY deletion_scheduled_at ASC
         LIMIT 500"
    );
    $selectStmt->execute();
    $rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return 0;
    }

    $processed = 0;

    foreach ($rows as $row) {
        $userId = (int)$row['id'];
        if ($userId <= 0) {
            continue;
        }

        $db->beginTransaction();

        try {
            // Seguridad: invalidar confianza en dispositivos para bloquear nuevos accesos.
            try {
                $devicesStmt = $db->prepare('UPDATE user_devices SET trusted = 0, fail_attempts = 0, locked_until = NULL WHERE user_id = ?');
                $devicesStmt->execute([$userId]);
            } catch (Throwable $ignored) {
                // Tabla opcional en algunos ambientes.
            }

            // Limpieza de códigos de verificación en flujos sensibles.
            try {
                $codesStmt = $db->prepare('UPDATE account_deletion_codes SET used = TRUE, used_at = NOW() WHERE user_id = ? AND used = FALSE');
                $codesStmt->execute([$userId]);
            } catch (Throwable $ignored) {
                // Tabla opcional en ambientes desfasados.
            }

            $anonEmail = anonymizedEmailForUser($userId);
            $anonName = 'Cuenta eliminada';
            $anonLastName = 'Viax';
            $anonPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);

            // Cumplimiento: anonimización mínima para no exponer PII tras expiración del período de retracto.
            $updateStmt = $db->prepare(
                "UPDATE usuarios
                 SET status = 'deleted',
                     nombre = ?,
                     apellido = ?,
                     email = ?,
                     telefono = NULL,
                     foto_perfil = NULL,
                     hash_contrasena = ?,
                     deleted_at = NOW(),
                     deletion_requested_at = COALESCE(deletion_requested_at, NOW()),
                     deletion_scheduled_at = COALESCE(deletion_scheduled_at, NOW())
                 WHERE id = ?"
            );
            $updateStmt->execute([$anonName, $anonLastName, $anonEmail, $anonPassword, $userId]);

            $db->commit();
            $processed++;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[account_deletion_worker][user:' . $userId . '] ' . $e->getMessage());
        }
    }

    return $processed;
}

function runAccountDeletionWorker(): void
{
    $db = (new Database())->getConnection();

    while (true) {
        $startedAt = microtime(true);

        try {
            $processedCount = processPendingDeletionUsers($db);
            $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

            $redis = Cache::redis();
            if ($redis) {
                $redis->setex('workers:account_deletion:last_heartbeat', 90, (string)time());
                $redis->incrBy('workers:account_deletion:processed_total', $processedCount);
                $redis->set('workers:account_deletion:last_duration_ms', (string)$elapsedMs);
            }

            error_log('[account_deletion_worker] processed=' . $processedCount . ' duration_ms=' . $elapsedMs);
        } catch (Throwable $e) {
            error_log('[account_deletion_worker] ' . $e->getMessage());
        }

        sleep(ACCOUNT_DELETION_WORKER_INTERVAL_SEC);
    }
}

runAccountDeletionWorker();
