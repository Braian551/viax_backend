<?php
/**
 * Check if user exists by email
 * Endpoint: POST /auth/check_user.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    // Get POST data
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['email']) || empty($data['email'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Email es requerido'
        ]);
        exit();
    }

    $email = trim($data['email']);

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Formato de email inválido'
        ]);
        exit();
    }

    // Check database
    $database = new Database();
    $db = $database->getConnection();

    // Check if user exists in usuarios table
    $query = "SELECT id, tipo_usuario FROM usuarios WHERE email = :email LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Return that user exists with their type
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'exists' => true,
            'user_type' => $user['tipo_usuario'],
            'message' => 'Usuario encontrado'
        ]);
    } else {
        // User doesn't exist
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'exists' => false,
            'message' => 'Usuario no encontrado'
        ]);
    }

} catch (PDOException $e) {
    error_log("Database error in check_user.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al verificar usuario'
    ]);
} catch (Exception $e) {
    error_log("Error in check_user.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>