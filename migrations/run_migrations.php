<?php
/**
 * Compatibilidad legacy.
 * Este entrypoint delega al runner seguro/idempotente en scripts/.
 */

require_once __DIR__ . '/../scripts/run_migrations.php';

if (php_sapi_name() === 'cli') {
    runMigrations();
}
