<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    if (!isset($data['solicitud_id']) || !isset($data['conductor_id'])) {
        throw new Exception('Datos requeridos: solicitud_id, conductor_id');
    }
    
    $solicitudId = $data['solicitud_id'];
    $conductorId = $data['conductor_id'];
    $motivo = $data['motivo'] ?? 'Conductor rechazó';
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Registrar el rechazo (para no volver a mostrarle esta solicitud al mismo conductor)
    $stmt = $db->prepare("
        INSERT INTO rechazos_conductor (
            solicitud_id, 
            conductor_id, 
            motivo, 
            fecha_rechazo
        ) VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$solicitudId, $conductorId, $motivo]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Solicitud rechazada'
    ]);
    
} catch (Exception $e) {
    // Si la tabla no existe, solo retornar éxito
    if (strpos($e->getMessage(), "doesn't exist") !== false) {
        echo json_encode([
            'success' => true,
            'message' => 'Solicitud rechazada (registro no guardado)'
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
