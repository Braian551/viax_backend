<?php
/**
 * Cleanup Empresa Data Migration
 * 
 * This migration removes ALL empresa-related data:
 * - Empresa users (tipo_usuario = 'empresa')
 * - Conductores associated with empresas
 * - Empresas_transporte records
 * 
 * WARNING: This is destructive! Only run in development/testing.
 */

require_once __DIR__ . '/../config/database.php';

try {
    echo "=== Cleanup Empresa Data Migration ===\n\n";
    
    $database = new Database();
    $db = $database->getConnection();
    
    $db->beginTransaction();
    
    // 1. Get count of empresas before deletion
    $countQuery = "SELECT COUNT(*) as count FROM empresas_transporte";
    $countStmt = $db->query($countQuery);
    $empresaCount = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "Found $empresaCount empresa(s) to delete\n";
    
    // 2. Get count of empresa users
    $userCountQuery = "SELECT COUNT(*) as count FROM usuarios WHERE tipo_usuario = 'empresa'";
    $userCountStmt = $db->query($userCountQuery);
    $userCount = $userCountStmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "Found $userCount empresa user(s) to delete\n";
    
    // 3. Delete user_devices for empresa users
    echo "Step 1: Deleting user_devices for empresa users...\n";
    $deleteDevices = "DELETE FROM user_devices 
                      WHERE user_id IN (SELECT id FROM usuarios WHERE tipo_usuario = 'empresa')";
    $db->exec($deleteDevices);
    echo "✓ Deleted user_devices\n\n";
    
    // 4. Delete solicitudes_vinculacion_conductor for empresa users
    echo "Step 2: Deleting solicitudes_vinculacion_conductor...\n";
    $deleteSolicitudes = "DELETE FROM solicitudes_vinculacion_conductor 
                          WHERE procesado_por IN (SELECT id FROM usuarios WHERE tipo_usuario = 'empresa')";
    $db->exec($deleteSolicitudes);
    echo "✓ Deleted related solicitudes\n\n";
    
    // 5. Unlink ALL usuarios from empresas (break circular dependency and driver links)
    echo "Step 3: Unlinking ALL usuarios from empresas...\n";
    $unlinkUsers = "UPDATE usuarios SET empresa_id = NULL WHERE empresa_id IS NOT NULL";
    $db->exec($unlinkUsers);
    echo "✓ Unlinked all users from companies\n\n";

    // 6. Delete empresas_transporte
    echo "Step 4: Deleting empresas_transporte records...\n";
    $deleteEmpresas = "DELETE FROM empresas_transporte";
    $db->exec($deleteEmpresas);
    echo "✓ Deleted $empresaCount empresa(s)\n\n";

    // 7. Delete empresa users
    echo "Step 5: Deleting empresa users...\n";
    $deleteUsers = "DELETE FROM usuarios WHERE tipo_usuario = 'empresa'";
    $db->exec($deleteUsers);
    echo "✓ Deleted $userCount empresa user(s)\n\n";
    
    // 8. Reset sequence for empresas_transporte (so next ID is 1)
    echo "Step 6: Resetting ID sequence...\n";
    $resetSeq = "ALTER SEQUENCE empresas_transporte_id_seq RESTART WITH 1";
    $db->exec($resetSeq);
    echo "✓ Reset empresas_transporte ID sequence\n\n";
    
    $db->commit();
    
    echo "=== Migration Completed Successfully ===\n";
    echo "Summary:\n";
    echo "  - Deleted $empresaCount empresa(s)\n";
    echo "  - Deleted $userCount empresa user(s)\n";
    echo "  - Reset ID sequence\n";
    echo "Database is now clean for testing!\n";
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
