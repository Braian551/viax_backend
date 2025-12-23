<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $sql = file_get_contents('009_create_paradas_solicitud.sql');
    
    if (!$sql) {
        throw new Exception("Could not read migration file");
    }
    
    // Split by semicolon to handle multiple statements if any, though here it's mostly one block
    // But PDO might not handle multiple statements in one go depending on config.
    // For this simple file, we can try executing it directly if it's just one CREATE TABLE.
    // However, it has USE, SET, DROP, CREATE.
    
    // Let's execute the critical part.
    $db->exec($sql);
    
    echo "Migration 009 executed successfully.\n";
    
} catch (Exception $e) {
    echo "Error executing migration: " . $e->getMessage() . "\n";
    exit(1);
}
?>
