<?php
/**
 * request_callback.php
 * Crea una solicitud de callback (te llamamos)
 * 
 * POST params:
 * - usuario_id: (required) ID del usuario
 * - telefono: (required) Número de teléfono
 * - motivo: (optional) Motivo de la llamada
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $data = json_decode(file_get_contents("php://input"), true);
    
    $usuario_id = isset($data['usuario_id']) ? intval($data['usuario_id']) : 0;
    $telefono = isset($data['telefono']) ? trim($data['telefono']) : '';
    $motivo = isset($data['motivo']) ? trim($data['motivo']) : null;
    
    if ($usuario_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'usuario_id es requerido']);
        exit();
    }
    
    if (empty($telefono)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'telefono es requerido']);
        exit();
    }
    
    // Verificar si ya hay una solicitud pendiente reciente (últimas 24h)
    $checkQuery = "
        SELECT id FROM solicitudes_callback 
        WHERE usuario_id = :usuario_id 
        AND estado = 'pendiente' 
        AND created_at > NOW() - INTERVAL '24 hours'
    ";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $checkStmt->execute();
    
    if ($checkStmt->fetch()) {
        echo json_encode([
            'success' => false,
            'error' => 'Ya tienes una solicitud de callback pendiente. Te contactaremos pronto.'
        ]);
        exit();
    }
    
    // Insertar solicitud
    $query = "
        INSERT INTO solicitudes_callback (usuario_id, telefono, motivo)
        VALUES (:usuario_id, :telefono, :motivo)
        RETURNING id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $stmt->bindValue(':telefono', $telefono);
    $stmt->bindValue(':motivo', $motivo);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Solicitud de callback registrada. Te contactaremos pronto.',
        'callback_id' => $result['id']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al registrar callback: ' . $e->getMessage()
    ]);
}
