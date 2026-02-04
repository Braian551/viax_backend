<?php
// Debug script for settings endpoint

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting debug...\n";

try {
    require_once '../config/database.php';
    echo "Database config loaded.\n";

    require_once 'controllers/EmpresaController.php';
    echo "Controller loaded.\n";
    
    $database = new Database();
    $db = $database->getConnection();
    echo "DB Connection established.\n";
    
    $controller = new EmpresaController($db);
    echo "Controller initialized.\n";
    
    // Test GET
    echo "\nTesting GET settings for empresa_id=1...\n";
    $inputGet = [
        'action' => 'get_settings',
        'empresa_id' => 1
    ];
    // Capture output to avoid header errors if controller sends them
    ob_start();
    $controller->handleRequest($inputGet);
    $output = ob_get_clean();
    echo "GET Output: " . substr($output, 0, 100) . "...\n";
    
    // Test UPDATE
    echo "\nTesting UPDATE settings for empresa_id=1...\n";
    $inputPost = [
        'action' => 'update_settings',
        'empresa_id' => 1,
        'notificaciones_email' => false,
        'notificaciones_push' => true
    ];
    
    ob_start();
    $controller->handleRequest($inputPost);
    $output = ob_get_clean();
    echo "UPDATE Output: " . substr($output, 0, 100) . "...\n";
    
} catch (Throwable $e) {
    echo "\nFATAL ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
