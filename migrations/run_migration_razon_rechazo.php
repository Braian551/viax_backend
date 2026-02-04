<?php
/**
 * Migration: Add razon_rechazo column to detalles_conductor
 * This column stores the reason for document rejection
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');

echo "=== Migration: Add razon_rechazo to detalles_conductor ===\n\n";

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if column exists
    echo "1. Checking if column 'razon_rechazo' exists...\n";
    
    $checkSql = "SELECT column_name 
                 FROM information_schema.columns 
                 WHERE table_name = 'detalles_conductor' 
                 AND column_name = 'razon_rechazo'";
    $stmt = $db->query($checkSql);
    $exists = $stmt->fetch();
    
    if ($exists) {
        echo "   ✓ Column 'razon_rechazo' already exists!\n";
    } else {
        echo "   - Column does not exist, adding...\n";
        
        $alterSql = "ALTER TABLE detalles_conductor ADD COLUMN razon_rechazo TEXT";
        $db->exec($alterSql);
        
        echo "   ✓ Column 'razon_rechazo' added successfully!\n";
    }
    
    // Verify the change
    echo "\n2. Verifying column structure...\n";
    $verifySql = "SELECT column_name, data_type, is_nullable 
                  FROM information_schema.columns 
                  WHERE table_name = 'detalles_conductor' 
                  AND column_name IN ('estado_verificacion', 'razon_rechazo', 'fecha_ultima_verificacion')
                  ORDER BY ordinal_position";
    $stmt = $db->query($verifySql);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "   Found verification-related columns:\n";
    foreach ($columns as $col) {
        echo "   - {$col['column_name']}: {$col['data_type']} (nullable: {$col['is_nullable']})\n";
    }
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
