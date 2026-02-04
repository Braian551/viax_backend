<?php
/**
 * settings.php
 * Endpoint for managing company configuration settings
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/config.php';
require_once '../config/database.php';
require_once 'controllers/EmpresaController.php';

try {
    // Initialize Database
    $database = new Database();
    $db = $database->getConnection();
    
    // Initialize Controller
    $controller = new EmpresaController($db);
    
    // Get Request Data
    $method = $_SERVER['REQUEST_METHOD'];
    $rawInput = file_get_contents("php://input");
    $input = json_decode($rawInput, true);
    
    // Debug Logging
    $logFile = __DIR__ . '/settings_debug.log';
    $logEntry = date('Y-m-d H:i:s') . " - Method: $method - Input: $rawInput\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    // Fallback for $_REQUEST/$_POST
    if (empty($input)) {
        $input = $_REQUEST;
    }

    // Determine Action
    if (isset($_GET['action'])) {
        $action = $_GET['action'];
    } else {
        // Default actions based on method
        if ($method === 'GET') {
            $action = 'get_settings';
        } elseif ($method === 'POST') {
            $action = 'update_settings';
        } else {
            throw new Exception("Método no soportado");
        }
    }

    // Verify Authorization (Relaxed for MVP to match profile.php)
    // In production, middleware should handle this.
    // For now we check if empresa_id is present, which is handled in controller logic.
    
    // Add additional params to input for controller
    if (isset($input)) {
        $input['action'] = $action;
    } else {
        $input = ['action' => $action];
    }
    
    // Dispatch
    echo $controller->handleRequest($input);

} catch (Throwable $e) {
    if (isset($logFile)) {
        file_put_contents($logFile, "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
    }
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error interno: " . $e->getMessage()
    ]);
}
