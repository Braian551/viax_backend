<?php
/**
 * Script para ejecutar migraciones SQL
 * Uso: php migrations/run_migrations.php
 */

require_once __DIR__ . '/../config/database.php';

function isMySqlOnlyMigration(string $filename, string $sql): bool {
    $legacyFileNames = [
        'run_migration_003.sql',
        'run_migration_004.sql',
        'ejecutar_004_simple.sql',
    ];

    if (in_array($filename, $legacyFileNames, true)) {
        return true;
    }

    $patterns = [
        '/\bUSE\s+[\w`]+/i',
        '/\bSOURCE\b/i',
        '/\bDESCRIBE\b/i',
        '/\bSHOW\s+COLUMNS\b/i',
        '/\bON\s+DUPLICATE\s+KEY\b/i',
        '/\bENGINE\s*=\s*InnoDB\b/i',
        '/`[A-Za-z0-9_]+`/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $sql)) {
            return true;
        }
    }

    return false;
}

function runMigrations() {
    try {
        $database = new Database();
        $db = $database->getConnection();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        echo "=== Ejecutando Migraciones de Base de Datos ===\n\n";
        
        // Obtener todos los archivos SQL en orden
        $migrationFiles = glob(__DIR__ . '/*.sql');
        sort($migrationFiles);
        
        foreach ($migrationFiles as $file) {
            $filename = basename($file);
            echo "Ejecutando: $filename\n";
            
            $sql = trim((string) file_get_contents($file));
            if ($sql === '') {
                echo "  ⏭ Omitido (archivo vacío)\n\n";
                continue;
            }

            if ($driver === 'pgsql' && isMySqlOnlyMigration($filename, $sql)) {
                echo "  ⏭ Omitido (script legado MySQL no compatible con PostgreSQL)\n\n";
                continue;
            }

            try {
                if (!$db->inTransaction()) {
                    $db->beginTransaction();
                }

                $db->exec($sql);

                if ($db->inTransaction()) {
                    $db->commit();
                }

                echo "  ✓ Completado (archivo ejecutado)\n\n";
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                echo "  ⚠ Advertencia: " . $e->getMessage() . "\n";
                echo "  ✓ Completado (con advertencias)\n\n";
            }
        }
        
        echo "=== Migraciones completadas exitosamente ===\n";
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Ejecutar si se llama desde línea de comandos
if (php_sapi_name() === 'cli') {
    runMigrations();
}
