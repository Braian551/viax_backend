<?php
/**
 * Migration: Make telefono nullable for Google/Social sign-in users
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();
    
    echo "=== Making telefono nullable ===\n\n";
    
    // Allow null for telefono column
    $db->exec("ALTER TABLE usuarios ALTER COLUMN telefono DROP NOT NULL");
    
    echo "✓ telefono column is now nullable\n";
    echo "\n=== Migration Complete ===\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
