<?php
/**
 * Company Profile Endpoint
 * 
 * Handles retrieving and updating company profile data.
 * Requires authentication as 'empresa'.
 */

// Headers for CORS and JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/config.php';
require_once '../config/database.php';
require_once 'controllers/EmpresaController.php';

try {
    // 1. Initialize Database
    $database = new Database();
    $db = $database->getConnection();
    
    // 2. Validate Authentication (Basic check for now, can be expanded to proper JWT)
    // For now, we expect 'empresa_id' in the request, but in production this should verify validity
    // Here we will do a basic check if user is logged in via session or token if available
    
    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $jsonInput = json_decode(file_get_contents("php://input"), true);
        $input = is_array($jsonInput) ? $jsonInput : $_POST;
    } else {
        $input = $_GET;
    }
    
    // Security Check: simple validation that authorized ID matches requested ID would happen here
    // For this MVP, we pass the request to the controller which relies on service validation
    
    // 3. Determine Action
    $action = '';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = 'get_profile';
        $input['action'] = 'get_profile';
        // Map GET 'id' to 'empresa_id' if needed, though datasource sends 'empresa_id'
        if (isset($_GET['id']) && !isset($input['empresa_id'])) {
            $input['empresa_id'] = $_GET['id'];
        }
    } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = 'update_profile';
        $input['action'] = 'update_profile';
    }
    
    if (empty($input['empresa_id'])) {
        throw new Exception("ID de empresa no proporcionado");
    }
    
    // 4. Execute Controller
    $controller = new EmpresaController($db);
    $controller->handleRequest($input);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
