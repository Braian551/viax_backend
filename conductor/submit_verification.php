<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos inválidos');
    }

    $conductor_id = isset($input['conductor_id']) ? intval($input['conductor_id']) : 0;
    
    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Verify conductor exists and profile is complete
    $checkQuery = "SELECT 
                    u.id,
                    dc.licencia_conduccion,
                    dc.licencia_vencimiento,
                    dc.vehiculo_placa,
                    dc.estado_verificacion
                   FROM usuarios u
                   LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
                   WHERE u.id = :conductor_id AND u.tipo_usuario = 'conductor'";
    
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $checkStmt->execute();
    
    $conductor = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conductor) {
        throw new Exception('Conductor no encontrado');
    }

    // Validate profile completeness
    $errors = [];
    
    if (empty($conductor['licencia_conduccion'])) {
        $errors[] = 'Debes registrar tu licencia de conducción';
    }
    
    if (empty($conductor['licencia_vencimiento'])) {
        $errors[] = 'Debes registrar la fecha de vencimiento de tu licencia';
    } else {
        // Check if license is not expired
        $vencimiento = new DateTime($conductor['licencia_vencimiento']);
        $hoy = new DateTime();
        if ($vencimiento < $hoy) {
            $errors[] = 'Tu licencia de conducción está vencida';
        }
    }
    
    if (empty($conductor['vehiculo_placa'])) {
        $errors[] = 'Debes registrar tu vehículo';
    }

    if (!empty($errors)) {
        echo json_encode([
            'success' => false,
            'message' => 'Perfil incompleto',
            'errors' => $errors
        ]);
        exit();
    }

    // Check if already submitted
    if ($conductor['estado_verificacion'] === 'en_revision') {
        echo json_encode([
            'success' => true,
            'message' => 'Tu perfil ya está en revisión',
            'estado_verificacion' => 'en_revision'
        ]);
        exit();
    }

    if ($conductor['estado_verificacion'] === 'aprobado') {
        echo json_encode([
            'success' => true,
            'message' => 'Tu perfil ya está aprobado',
            'estado_verificacion' => 'aprobado'
        ]);
        exit();
    }

    // Update verification status
    $updateQuery = "UPDATE detalles_conductor 
                    SET estado_verificacion = 'en_revision',
                        fecha_ultima_verificacion = NOW(),
                        actualizado_en = NOW()
                    WHERE usuario_id = :conductor_id";
    
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $updateStmt->execute();

    // Log the submission
    $logQuery = "INSERT INTO logs_auditoria (
                    usuario_id, 
                    accion, 
                    entidad, 
                    entidad_id, 
                    descripcion,
                    fecha_creacion
                 ) VALUES (
                    :usuario_id,
                    'submit_verification',
                    'detalles_conductor',
                    :conductor_id,
                    'Conductor envió perfil para verificación',
                    NOW()
                 )";
    
    $logStmt = $db->prepare($logQuery);
    $logStmt->bindParam(':usuario_id', $conductor_id, PDO::PARAM_INT);
    $logStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $logStmt->execute();

    echo json_encode([
        'success' => true,
        'message' => '¡Perfil enviado para verificación! Te notificaremos cuando sea aprobado.',
        'estado_verificacion' => 'en_revision'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
