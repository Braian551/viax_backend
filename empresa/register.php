<?php
/**
 * Registro de Empresas de Transporte - API Endpoint
 * 
 * Este archivo gestiona el registro público de empresas de transporte.
 * Ahora usa arquitectura OOP con separación de responsabilidades.
 * 
 * POST action=register - Registrar nueva empresa con usuario administrador
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable display_errors to return clean JSON
ini_set('log_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Handle fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_COMPILE_ERROR)) {
        // Build JSON error response
        if (ob_get_length()) ob_end_clean(); // Clean buffer
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Error fatal del servidor',
            'debug_error' => $error['message'],
            'debug_file' => basename($error['file']),
            'debug_line' => $error['line']
        ]);
        exit;
    }
});

require_once '../config/config.php';
require_once __DIR__ . '/controllers/EmpresaController.php';

// getJsonInput helper is already defined in config/config.php

try {
    // Start output buffering to catch any unexpected output
    ob_start();

    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();
    
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean(); // Discard any garbage output
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
        exit();
    }
    
    // Parse input based on content type
    $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = getJsonInput();
    } else {
        $input = $_POST;
    }
    
    // Clean buffer before delegating to controller (Controller will echo JSON)
    ob_end_clean();
    
    // Delegate to controller
    $controller = new EmpresaController($db);
    $controller->handleRequest($input);
    
} catch (Exception $e) {
    // Clean any buffer if error occurred
    if (ob_get_length()) ob_end_clean();
    
    error_log("Error en empresa/register.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'debug_error' => $e->getMessage(),
        'debug_line' => $e->getLine(),
        'debug_file' => basename($e->getFile())
    ]);
}
