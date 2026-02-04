<?php
/**
 * change_password.php
 * 
 * Endpoint for changing user password.
 * Works with any user type (empresa, admin, conductor, cliente).
 * 
 * Handles the Google OAuth paradox: users without passwords can SET a new one,
 * users with passwords must VERIFY their current password first.
 * 
 * Actions:
 *   - check_status: Returns if user has a password and their auth provider
 *   - change_password: Changes password (requires current if has one)
 *   - set_password: Sets password for OAuth users without one
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/database.php';
require_once 'services/AuthService.php';

try {
    // Initialize
    $database = new Database();
    $db = $database->getConnection();
    $authService = new AuthService($db);
    
    // Parse input
    $input = json_decode(file_get_contents("php://input"), true);
    if (empty($input)) {
        $input = $_REQUEST;
    }
    
    // Validate user_id
    $userId = $input['user_id'] ?? null;
    if (empty($userId)) {
        throw new Exception("ID de usuario requerido");
    }
    
    // Determine action
    $action = $input['action'] ?? 'check_status';
    
    switch ($action) {
        case 'check_status':
            // Check if user has password and their auth provider
            $status = $authService->checkPasswordStatus($userId);
            echo json_encode([
                'success' => true,
                'message' => 'Estado verificado',
                'data' => $status
            ]);
            break;
            
        case 'change_password':
            // User wants to change their password
            $currentPassword = $input['current_password'] ?? null;
            $newPassword = $input['new_password'] ?? null;
            
            if (empty($newPassword)) {
                throw new Exception("Nueva contraseña requerida");
            }
            
            $authService->changePassword($userId, $currentPassword, $newPassword);
            
            echo json_encode([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente'
            ]);
            break;
            
        case 'set_password':
            // For OAuth users setting password for first time
            $newPassword = $input['new_password'] ?? null;
            
            if (empty($newPassword)) {
                throw new Exception("Nueva contraseña requerida");
            }
            
            $authService->setPassword($userId, $newPassword);
            
            echo json_encode([
                'success' => true,
                'message' => 'Contraseña establecida exitosamente'
            ]);
            break;
            
        default:
            throw new Exception("Acción no válida: $action");
    }
    
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
