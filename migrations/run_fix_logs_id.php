<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Running migration to fix 'logs_auditoria.id' auto-increment...\n";
    
    // 1. Check if sequence exists (optional, simply trying to create and catch error is also fine but explicit check is better)
    // For simplicity, we use IF NOT EXISTS in SQL if supported, or just catch exception.
    // PostgreSQL supports CREATE SEQUENCE IF NOT EXISTS.

    // 2. Create Sequence
    $db->exec("CREATE SEQUENCE IF NOT EXISTS logs_auditoria_id_seq");
    echo "Sequence 'logs_auditoria_id_seq' ensured.\n";
    
    // 3. Set default value for id column
    $db->exec("ALTER TABLE logs_auditoria ALTER COLUMN id SET DEFAULT nextval('logs_auditoria_id_seq')");
    echo "Set default value for 'logs_auditoria.id' to nextval('logs_auditoria_id_seq').\n";
    
    // 4. Bind sequence to column
    $db->exec("ALTER SEQUENCE logs_auditoria_id_seq OWNED BY logs_auditoria.id");
    echo "Bound sequence to column.\n";

    // 5. Sync sequence with current max id
    $db->exec("SELECT setval('logs_auditoria_id_seq', COALESCE((SELECT MAX(id) FROM logs_auditoria), 0) + 1, false)");
    echo "Synced sequence value.\n";
    
    echo "Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
