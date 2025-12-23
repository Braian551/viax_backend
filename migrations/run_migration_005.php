<?php
/**
 * Migration Script: Add Vehicle Registration Fields
 * 
 * This script adds the missing columns to the detalles_conductor table
 * to support complete vehicle and license information
 */

require_once '../config/database.php';

echo "=== Migration: Add Vehicle Registration Fields ===\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Connected to database successfully.\n\n";
    
    // Check current table structure
    echo "Checking current table structure...\n";
    $checkQuery = "DESCRIBE detalles_conductor";
    $stmt = $db->query($checkQuery);
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Existing columns: " . count($existingColumns) . "\n\n";
    
    // Columns to add
    $columnsToAdd = [
        'licencia_expedicion' => "ALTER TABLE `detalles_conductor` ADD COLUMN `licencia_expedicion` DATE NULL AFTER `licencia_vencimiento`",
        'licencia_categoria' => "ALTER TABLE `detalles_conductor` ADD COLUMN `licencia_categoria` VARCHAR(10) NULL DEFAULT 'C1' AFTER `licencia_expedicion`",
        'soat_numero' => "ALTER TABLE `detalles_conductor` ADD COLUMN `soat_numero` VARCHAR(50) NULL AFTER `vencimiento_seguro`",
        'soat_vencimiento' => "ALTER TABLE `detalles_conductor` ADD COLUMN `soat_vencimiento` DATE NULL AFTER `soat_numero`",
        'tecnomecanica_numero' => "ALTER TABLE `detalles_conductor` ADD COLUMN `tecnomecanica_numero` VARCHAR(50) NULL AFTER `soat_vencimiento`",
        'tecnomecanica_vencimiento' => "ALTER TABLE `detalles_conductor` ADD COLUMN `tecnomecanica_vencimiento` DATE NULL AFTER `tecnomecanica_numero`",
        'tarjeta_propiedad_numero' => "ALTER TABLE `detalles_conductor` ADD COLUMN `tarjeta_propiedad_numero` VARCHAR(50) NULL AFTER `tecnomecanica_vencimiento`"
    ];
    
    $db->beginTransaction();
    
    // Add missing columns
    foreach ($columnsToAdd as $columnName => $query) {
        if (!in_array($columnName, $existingColumns)) {
            echo "Adding column: $columnName... ";
            try {
                $db->exec($query);
                echo "✓ Done\n";
            } catch (PDOException $e) {
                echo "✗ Error: " . $e->getMessage() . "\n";
                // Continue with other columns
            }
        } else {
            echo "Column already exists: $columnName ✓\n";
        }
    }
    
    echo "\n";
    
    // Add indexes for better performance
    echo "Adding indexes for better performance...\n";
    
    $indexes = [
        'idx_licencia_vencimiento' => "CREATE INDEX `idx_licencia_vencimiento` ON `detalles_conductor` (`licencia_vencimiento`)",
        'idx_soat_vencimiento' => "CREATE INDEX `idx_soat_vencimiento` ON `detalles_conductor` (`soat_vencimiento`)",
        'idx_tecnomecanica_vencimiento' => "CREATE INDEX `idx_tecnomecanica_vencimiento` ON `detalles_conductor` (`tecnomecanica_vencimiento`)"
    ];
    
    foreach ($indexes as $indexName => $query) {
        echo "Creating index: $indexName... ";
        try {
            $db->exec($query);
            echo "✓ Done\n";
        } catch (PDOException $e) {
            // Index might already exist
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                echo "✓ Already exists\n";
            } else {
                echo "✗ Error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    $db->commit();
    
    echo "\n=== Migration completed successfully! ===\n\n";
    
    // Show updated structure
    echo "Updated table structure:\n";
    $stmt = $db->query("DESCRIBE detalles_conductor");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $column) {
        echo sprintf("  - %-35s %s\n", $column['Field'], $column['Type']);
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
