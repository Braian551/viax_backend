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
    $checkQuery = "SELECT id FROM usuarios WHERE id = :conductor_id AND tipo_usuario = 'conductor'";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() === 0) {
        throw new Exception('Conductor no encontrado');
    }

    // Extract vehicle data
    $vehiculo_tipo = isset($input['vehiculo_tipo']) ? $input['vehiculo_tipo'] : 'moto';
    $vehiculo_marca = isset($input['vehiculo_marca']) ? $input['vehiculo_marca'] : null;
    $vehiculo_modelo = isset($input['vehiculo_modelo']) ? $input['vehiculo_modelo'] : null;
    $vehiculo_anio = isset($input['vehiculo_anio']) ? intval($input['vehiculo_anio']) : null;
    $vehiculo_color = isset($input['vehiculo_color']) ? $input['vehiculo_color'] : null;
    $vehiculo_placa = isset($input['vehiculo_placa']) ? strtoupper($input['vehiculo_placa']) : '';
    
    // Insurance data
    $aseguradora = isset($input['aseguradora']) ? $input['aseguradora'] : null;
    $numero_poliza_seguro = isset($input['numero_poliza_seguro']) ? $input['numero_poliza_seguro'] : null;
    $vencimiento_seguro = isset($input['vencimiento_seguro']) ? date('Y-m-d', strtotime($input['vencimiento_seguro'])) : null;
    
    // SOAT data
    $soat_numero = isset($input['soat_numero']) ? $input['soat_numero'] : null;
    $soat_vencimiento = isset($input['soat_vencimiento']) ? date('Y-m-d', strtotime($input['soat_vencimiento'])) : null;
    
    // Tecnomecanica data
    $tecnomecanica_numero = isset($input['tecnomecanica_numero']) ? $input['tecnomecanica_numero'] : null;
    $tecnomecanica_vencimiento = isset($input['tecnomecanica_vencimiento']) ? date('Y-m-d', strtotime($input['tecnomecanica_vencimiento'])) : null;
    
    // Tarjeta de propiedad
    $tarjeta_propiedad_numero = isset($input['tarjeta_propiedad_numero']) ? $input['tarjeta_propiedad_numero'] : null;

    if (empty($vehiculo_placa)) {
        throw new Exception('La placa del vehículo es requerida');
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
                        vehiculo_tipo = :vehiculo_tipo,
                        vehiculo_marca = :vehiculo_marca,
                        vehiculo_modelo = :vehiculo_modelo,
                        vehiculo_anio = :vehiculo_anio,
                        vehiculo_color = :vehiculo_color,
                        vehiculo_placa = :vehiculo_placa,
                        aseguradora = :aseguradora,
                        numero_poliza_seguro = :numero_poliza_seguro,
                        vencimiento_seguro = :vencimiento_seguro,
                        soat_numero = :soat_numero,
                        soat_vencimiento = :soat_vencimiento,
                        tecnomecanica_numero = :tecnomecanica_numero,
                        tecnomecanica_vencimiento = :tecnomecanica_vencimiento,
                        tarjeta_propiedad_numero = :tarjeta_propiedad_numero,
                        actualizado_en = NOW()
                        WHERE usuario_id = :usuario_id";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':vehiculo_tipo', $vehiculo_tipo);
        $updateStmt->bindParam(':vehiculo_marca', $vehiculo_marca);
        $updateStmt->bindParam(':vehiculo_modelo', $vehiculo_modelo);
        $updateStmt->bindParam(':vehiculo_anio', $vehiculo_anio, PDO::PARAM_INT);
        $updateStmt->bindParam(':vehiculo_color', $vehiculo_color);
        $updateStmt->bindParam(':vehiculo_placa', $vehiculo_placa);
        $updateStmt->bindParam(':aseguradora', $aseguradora);
        $updateStmt->bindParam(':numero_poliza_seguro', $numero_poliza_seguro);
        $updateStmt->bindParam(':vencimiento_seguro', $vencimiento_seguro);
        $updateStmt->bindParam(':soat_numero', $soat_numero);
        $updateStmt->bindParam(':soat_vencimiento', $soat_vencimiento);
        $updateStmt->bindParam(':tecnomecanica_numero', $tecnomecanica_numero);
        $updateStmt->bindParam(':tecnomecanica_vencimiento', $tecnomecanica_vencimiento);
        $updateStmt->bindParam(':tarjeta_propiedad_numero', $tarjeta_propiedad_numero);
        $updateStmt->bindParam(':usuario_id', $conductor_id, PDO::PARAM_INT);
        $updateStmt->execute();
    } else {
        // Insert new record with default values for required fields
        $insertQuery = "INSERT INTO detalles_conductor (
            usuario_id,
            licencia_conduccion,
            licencia_vencimiento,
            vehiculo_tipo,
            vehiculo_marca,
            vehiculo_modelo,
            vehiculo_anio,
            vehiculo_color,
            vehiculo_placa,
            aseguradora,
            numero_poliza_seguro,
            vencimiento_seguro,
            soat_numero,
            soat_vencimiento,
            tecnomecanica_numero,
            tecnomecanica_vencimiento,
            tarjeta_propiedad_numero,
            creado_en,
            actualizado_en
        ) VALUES (
            :usuario_id,
            'TEMP',
            DATE_ADD(NOW(), INTERVAL 1 YEAR),
            :vehiculo_tipo,
            :vehiculo_marca,
            :vehiculo_modelo,
            :vehiculo_anio,
            :vehiculo_color,
            :vehiculo_placa,
            :aseguradora,
            :numero_poliza_seguro,
            :vencimiento_seguro,
            :soat_numero,
            :soat_vencimiento,
            :tecnomecanica_numero,
            :tecnomecanica_vencimiento,
            :tarjeta_propiedad_numero,
            NOW(),
            NOW()
        )";

        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(':usuario_id', $conductor_id, PDO::PARAM_INT);
        $insertStmt->bindParam(':vehiculo_tipo', $vehiculo_tipo);
        $insertStmt->bindParam(':vehiculo_marca', $vehiculo_marca);
        $insertStmt->bindParam(':vehiculo_modelo', $vehiculo_modelo);
        $insertStmt->bindParam(':vehiculo_anio', $vehiculo_anio, PDO::PARAM_INT);
        $insertStmt->bindParam(':vehiculo_color', $vehiculo_color);
        $insertStmt->bindParam(':vehiculo_placa', $vehiculo_placa);
        $insertStmt->bindParam(':aseguradora', $aseguradora);
        $insertStmt->bindParam(':numero_poliza_seguro', $numero_poliza_seguro);
        $insertStmt->bindParam(':vencimiento_seguro', $vencimiento_seguro);
        $insertStmt->bindParam(':soat_numero', $soat_numero);
        $insertStmt->bindParam(':soat_vencimiento', $soat_vencimiento);
        $insertStmt->bindParam(':tecnomecanica_numero', $tecnomecanica_numero);
        $insertStmt->bindParam(':tecnomecanica_vencimiento', $tecnomecanica_vencimiento);
        $insertStmt->bindParam(':tarjeta_propiedad_numero', $tarjeta_propiedad_numero);
        $insertStmt->execute();
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Vehículo actualizado exitosamente'
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
