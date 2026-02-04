<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Running migration to fix 'tipo_usuario' CHECK constraint...\n";
    
    // 1. Drop existing constraint
    $db->exec("ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_tipo_usuario_check");
    echo "Dropped constraint 'usuarios_tipo_usuario_check' (if it existed).\n";
    
    // 2. Add new constraint
    $db->exec("ALTER TABLE usuarios ADD CONSTRAINT usuarios_tipo_usuario_check CHECK (tipo_usuario IN ('cliente', 'conductor', 'administrador', 'empresa'))");
    echo "Added new constraint 'usuarios_tipo_usuario_check' with 'empresa' included.\n";
    
    echo "Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
