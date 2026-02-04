<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $sql = file_get_contents('010_verification_tables.sql');
    
    if (!$sql) {
        throw new Exception("Could not read migration file");
    }
    
    // Split statements if needed, but usually simple CREATE/ALTER works in exec for MySQL
    // If multiple statements cause issues, we might need granular exec.
    // Here we have CREATE, ALTER, CREATE INDEX.
    
    // Attempt raw execution
    $db->exec($sql);
    
    echo "Migration 010 executed.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
