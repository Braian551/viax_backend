<?php
/**
 * Runner de migraciones idempotente (entorno producción).
 *
 * Uso:
 *   php backend/scripts/run_migrations.php
 */

require_once __DIR__ . '/../config/database.php';

function detectCurrentDriver(PDO $db): string
{
    return (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function isLegacyMySqlSql(string $sql): bool
{
    $patterns = [
        '/ENGINE\s*=\s*InnoDB/i',
        '/AUTO_INCREMENT/i',
        '/`[^`]+`/',
        '/\bTINYINT\b/i',
        '/\bENUM\s*\(/i',
        '/\bSOURCE\b/i',
        '/\bDESCRIBE\b/i',
        '/\bUSE\b\s+[`\w]+/i',
        '/\bCOMMENT\b\s+\'/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $sql)) {
            return true;
        }
    }

    return false;
}

function allowsDataMigrations(): bool
{
    return getenv('VIAX_ALLOW_DATA_MIGRATIONS') === '1';
}

function hasDataMutationStatements(string $sql): bool
{
    $patterns = [
        '/\bUPDATE\b/i',
        '/\bDELETE\s+FROM\b/i',
        '/\bTRUNCATE\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $sql)) {
            return true;
        }
    }

    return false;
}

function shouldBlockDataMutationMigration(string $filename, string $sql): bool
{
    if (allowsDataMigrations()) {
        return false;
    }

    // Permite override explícito dentro del SQL cuando se requiera un release controlado.
    if (stripos($sql, '@allow-data-migration') !== false) {
        return false;
    }

    // Bloqueo explícito para evitar reseteo de comisiones en producción.
    $hardBlocked = [
        '024_set_commissions_zero.sql',
    ];

    if (in_array($filename, $hardBlocked, true)) {
        return true;
    }

    return hasDataMutationStatements($sql);
}

/**
 * Crea tabla de control para evitar re-ejecutar scripts ya aplicados.
 */
function ensureSchemaMigrationsTable(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGSERIAL PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            checksum VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'applied',
            notes TEXT NULL,
            executed_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )"
    );

    // Normaliza instalaciones antiguas donde la tabla no tenía columnas de estado.
    $db->exec("ALTER TABLE schema_migrations ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT 'applied'");
    $db->exec("ALTER TABLE schema_migrations ADD COLUMN IF NOT EXISTS notes TEXT NULL");
    $db->exec("ALTER TABLE schema_migrations ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
}

function getMigrationRecord(PDO $db, string $filename): ?array
{
    $stmt = $db->prepare('SELECT checksum, status FROM schema_migrations WHERE filename = :filename LIMIT 1');
    $stmt->execute([':filename' => $filename]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return null;
    }

    return [
        'checksum' => (string)($row['checksum'] ?? ''),
        'status' => (string)($row['status'] ?? 'applied'),
    ];
}

function shouldExecuteMigration(string $filename, string $currentChecksum, ?array $record): bool
{
    if ($record === null) {
        return true;
    }

    $storedChecksum = (string)($record['checksum'] ?? '');

    if ($storedChecksum !== '' && !hash_equals($storedChecksum, $currentChecksum)) {
        throw new RuntimeException(
            "Migration '$filename' was modified after execution. " .
            "Stored checksum: $storedChecksum | Current checksum: $currentChecksum"
        );
    }

    return false;
}

function upsertMigrationRecord(PDO $db, string $filename, string $checksum, string $status = 'applied', ?string $notes = null): void
{
    $stmt = $db->prepare(
        'INSERT INTO schema_migrations (filename, checksum, status, notes)
         VALUES (:filename, :checksum, :status, :notes)
         ON CONFLICT (filename)
         DO UPDATE SET
            checksum = EXCLUDED.checksum,
            status = EXCLUDED.status,
            notes = EXCLUDED.notes,
            updated_at = NOW()'
    );

    $stmt->execute([
        ':filename' => $filename,
        ':checksum' => $checksum,
        ':status' => $status,
        ':notes' => $notes,
    ]);
}

function extractSqlState(Throwable $e): string
{
    if ($e instanceof PDOException) {
        $code = (string)$e->getCode();
        if ($code !== '') {
            return $code;
        }
    }

    $message = $e->getMessage();
    if (preg_match('/SQLSTATE\[([0-9A-Z]+)\]/i', $message, $matches) === 1) {
        return strtoupper((string)$matches[1]);
    }

    return '';
}

function isKnownCompatibilitySkip(string $filename, string $sqlState): bool
{
    $rules = [
        '015_rating_and_payment_tables.sql' => ['42703'],
        '026_add_routing_to_documents.sql' => ['42701'],
        '035_add_price_breakdown_columns.sql' => ['42710'],
    ];

    if (!isset($rules[$filename])) {
        return false;
    }

    return in_array($sqlState, $rules[$filename], true);
}

function runMigrations(): int
{
    $db = (new Database())->getConnection();
    $driver = detectCurrentDriver($db);
    $lockAcquired = false;

    try {
        $db->query('SELECT pg_advisory_lock(987654321)');
        $lockAcquired = true;

        ensureSchemaMigrationsTable($db);

        $files = glob(__DIR__ . '/../migrations/*.sql');
        sort($files);

        $executed = 0;
        foreach ($files as $file) {
            $filename = basename($file);
            $sql = trim((string)file_get_contents($file));
            if ($sql === '') {
                continue;
            }

            $checksum = hash('sha256', $sql);
            $record = getMigrationRecord($db, $filename);
            $isLegacyMySql = $driver === 'pgsql' && isLegacyMySqlSql($sql);

            if ($isLegacyMySql) {
                if ($record === null) {
                    upsertMigrationRecord(
                        $db,
                        $filename,
                        $checksum,
                        'ignored_legacy',
                        'skipped legacy migration: SQL MySQL no compatible con PostgreSQL.'
                    );
                    echo "[SKIP_LEGACY] $filename (ignored_legacy)\n";
                    continue;
                }

                $status = (string)($record['status'] ?? 'applied');
                if ($status === '' || $status === 'applied') {
                    upsertMigrationRecord(
                        $db,
                        $filename,
                        $checksum,
                        'legacy_applied',
                        'Migración legacy detectada como aplicada históricamente.'
                    );
                    echo "[LEGACY_APPLIED] $filename\n";
                } else {
                    echo "[SKIP_LEGACY] $filename ($status)\n";
                }
                continue;
            }

            if (!shouldExecuteMigration($filename, $checksum, $record)) {
                $status = (string)($record['status'] ?? 'applied');
                echo "[SKIP] $filename (ya ejecutada, estado=$status)\n";
                continue;
            }

            if (shouldBlockDataMutationMigration($filename, $sql)) {
                upsertMigrationRecord(
                    $db,
                    $filename,
                    $checksum,
                    'sealed_data_policy',
                    'Bloqueada por política de no mutación de datos en producción.'
                );
                echo "[SEALED] $filename (bloqueada por política: no mutar datos actuales)\n";
                continue;
            }

            try {
                $db->beginTransaction();
                $db->exec($sql);
                upsertMigrationRecord($db, $filename, $checksum, 'applied', null);
                $db->commit();
                $executed++;
                echo "[OK] $filename\n";
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                $sqlState = extractSqlState($e);
                if (isKnownCompatibilitySkip($filename, $sqlState)) {
                    upsertMigrationRecord(
                        $db,
                        $filename,
                        $checksum,
                        'applied_compat',
                        'Marcada como aplicada por compatibilidad (SQLSTATE ' . $sqlState . ').'
                    );
                    echo "[SKIP_COMPAT] $filename (sqlstate=$sqlState)\n";
                    continue;
                }

                echo "[WARN] $filename => " . $e->getMessage() . "\n";
            }
        }

        echo "Migraciones ejecutadas en esta corrida: $executed\n";
        return $executed;
    } finally {
        if ($lockAcquired) {
            $db->query('SELECT pg_advisory_unlock(987654321)');
        }
    }
}

if (php_sapi_name() === 'cli') {
    runMigrations();
}
