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

    // Verify conductor exists
    $checkQuery = "SELECT id FROM usuarios WHERE id = :conductor_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() === 0) {
        throw new Exception('Conductor no encontrado');
    }

    // Extract license data
    $licencia_numero = isset($input['licencia_conduccion']) ? $input['licencia_conduccion'] : '';
    $licencia_expedicion = isset($input['licencia_expedicion']) ? date('Y-m-d', strtotime($input['licencia_expedicion'])) : null;
    $licencia_vencimiento = isset($input['licencia_vencimiento']) ? date('Y-m-d', strtotime($input['licencia_vencimiento'])) : null;
    $licencia_categoria = isset($input['licencia_categoria']) ? $input['licencia_categoria'] : 'C1';

    if (empty($licencia_numero)) {
        throw new Exception('El número de licencia es requerido');
    }

    if (!$licencia_vencimiento) {
        throw new Exception('La fecha de vencimiento es requerida');
    }

    $db->beginTransaction();

    // Check if detalles_conductor exists
    $checkDetallesQuery = "SELECT id FROM detalles_conductor WHERE usuario_id = :usuario_id";
    $checkDetallesStmt = $db->prepare($checkDetallesQuery);
    $checkDetallesStmt->bindParam(':usuario_id', $conductor_id, PDO::PARAM_INT);
    $checkDetallesStmt->execute();

    if ($checkDetallesStmt->rowCount() > 0) {
        // Update existing record
        $updateQuery = "UPDATE detalles_conductor SET 
                        licencia_conduccion = :licencia_numero,
                        licencia_expedicion = :licencia_expedicion,
                        licencia_vencimiento = :licencia_vencimiento,
                        licencia_categoria = :licencia_categoria,
                        actualizado_en = NOW()
                        WHERE usuario_id = :usuario_id";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':licencia_numero', $licencia_numero);
        $updateStmt->bindParam(':licencia_expedicion', $licencia_expedicion);
        $updateStmt->bindParam(':licencia_vencimiento', $licencia_vencimiento);
        $updateStmt->bindParam(':licencia_categoria', $licencia_categoria);
        $updateStmt->bindParam(':usuario_id', $conductor_id, PDO::PARAM_INT);
        $updateStmt->execute();
    } else {
        // Insert new record with default values for required fields
        $insertQuery = "INSERT INTO detalles_conductor (
            usuario_id, 
            licencia_conduccion, 
            licencia_expedicion,
            licencia_vencimiento,
            licencia_categoria,
            vehiculo_tipo, 
            vehiculo_placa,
            creado_en,
            actualizado_en
        ) VALUES (
            :usuario_id, 
            :licencia_numero,
            :licencia_expedicion,
            :licencia_vencimiento,
            :licencia_categoria,
            'moto',
            '',
            NOW(),
            NOW()
        )";

        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(':usuario_id', $conductor_id, PDO::PARAM_INT);
        $insertStmt->bindParam(':licencia_numero', $licencia_numero);
        $insertStmt->bindParam(':licencia_expedicion', $licencia_expedicion);
        $insertStmt->bindParam(':licencia_vencimiento', $licencia_vencimiento);
        $insertStmt->bindParam(':licencia_categoria', $licencia_categoria);
        $insertStmt->execute();
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Licencia actualizada exitosamente'
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
