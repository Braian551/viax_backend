<?php
/**
 * Verifica si existen migraciones pendientes sin ejecutarlas.
 *
 * Uso:
 *   php backend/scripts/check_pending_migrations.php
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

function getMigrationRecords(PDO $db): array
{
    $stmt = $db->query('SELECT filename, checksum, status FROM schema_migrations');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];

    foreach ($rows as $row) {
        $map[(string)$row['filename']] = [
            'checksum' => (string)$row['checksum'],
            'status' => (string)($row['status'] ?? 'applied'),
        ];
    }

    return $map;
}

function upsertMigrationRecord(PDO $db, string $filename, string $checksum, string $status, ?string $notes = null): void
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

function checkPendingMigrations(): int
{
    $db = (new Database())->getConnection();
    $driver = detectCurrentDriver($db);
    ensureSchemaMigrationsTable($db);

    $files = glob(__DIR__ . '/../migrations/*.sql');
    sort($files);

    $records = getMigrationRecords($db);
    $pending = [];
    $legacyApplied = 0;
    $legacyIgnored = 0;

    foreach ($files as $file) {
        $filename = basename($file);
        $sql = trim((string)file_get_contents($file));
        if ($sql === '') {
            continue;
        }

        $checksum = hash('sha256', $sql);
        $isLegacyMySql = $driver === 'pgsql' && isLegacyMySqlSql($sql);

        if ($isLegacyMySql) {
            $record = $records[$filename] ?? null;

            if ($record === null) {
                upsertMigrationRecord(
                    $db,
                    $filename,
                    $checksum,
                    'ignored_legacy',
                    'Migración legacy MySQL ignorada en PostgreSQL por compatibilidad.'
                );
                $legacyIgnored++;
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
                $legacyApplied++;
            } elseif ($status === 'legacy_applied') {
                $legacyApplied++;
            } else {
                $legacyIgnored++;
            }

            continue;
        }

        if (!isset($records[$filename])) {
            $pending[] = $filename;
        }
    }

    if (!empty($pending)) {
        echo "PENDING_MIGRATIONS_FOUND\n";
        foreach ($pending as $filename) {
            echo $filename . "\n";
        }
        return 1;
    }

    echo "NO_PENDING_MIGRATIONS\n";
    if ($driver === 'pgsql') {
        echo "LEGACY_NORMALIZED applied=$legacyApplied ignored=$legacyIgnored\n";
    }
    return 0;
}

if (php_sapi_name() === 'cli') {
    exit(checkPendingMigrations());
}
