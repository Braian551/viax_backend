<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Running migration 023 (Company Pricing)...\n";
    
    $sql = file_get_contents('023_company_pricing.sql');
    
    // Split by simple commands if needed, or run at once. 
    // Since PDO can handle multiple statements in some drivers, 
    // but typically explicit execution is safer. 
    // HACK: for this simple script we assume the file content is safe to run directly.
    // Ideally we should split by ';' but CREATE TRIGGER/FUNCTION uses ';' inside bodies.
    // Since this migration only uses standard SQL, we can try running it whole.
    
    $db->exec($sql);
    
    echo "Migration 023 completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
