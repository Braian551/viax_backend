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

    $db->beginTransaction();

    // Update detalles_conductor
    $updateFields = [];
    $params = [':usuario_id' => $conductor_id];

    // License info
    if (isset($input['licencia_conduccion'])) {
        $updateFields[] = "licencia_conduccion = :licencia_conduccion";
        $params[':licencia_conduccion'] = $input['licencia_conduccion'];
    }
    if (isset($input['licencia_vencimiento'])) {
        $updateFields[] = "licencia_vencimiento = :licencia_vencimiento";
        $params[':licencia_vencimiento'] = $input['licencia_vencimiento'];
    }

    // Vehicle info
    if (isset($input['vehiculo_tipo'])) {
        $updateFields[] = "vehiculo_tipo = :vehiculo_tipo";
        $params[':vehiculo_tipo'] = $input['vehiculo_tipo'];
    }
    if (isset($input['vehiculo_marca'])) {
        $updateFields[] = "vehiculo_marca = :vehiculo_marca";
        $params[':vehiculo_marca'] = $input['vehiculo_marca'];
    }
    if (isset($input['vehiculo_modelo'])) {
        $updateFields[] = "vehiculo_modelo = :vehiculo_modelo";
        $params[':vehiculo_modelo'] = $input['vehiculo_modelo'];
    }
    if (isset($input['vehiculo_anio'])) {
        $updateFields[] = "vehiculo_anio = :vehiculo_anio";
        $params[':vehiculo_anio'] = intval($input['vehiculo_anio']);
    }
    if (isset($input['vehiculo_color'])) {
        $updateFields[] = "vehiculo_color = :vehiculo_color";
        $params[':vehiculo_color'] = $input['vehiculo_color'];
    }
    if (isset($input['vehiculo_placa'])) {
        $updateFields[] = "vehiculo_placa = :vehiculo_placa";
        $params[':vehiculo_placa'] = strtoupper($input['vehiculo_placa']);
    }

    // Insurance info
    if (isset($input['aseguradora'])) {
        $updateFields[] = "aseguradora = :aseguradora";
        $params[':aseguradora'] = $input['aseguradora'];
    }
    if (isset($input['numero_poliza_seguro'])) {
        $updateFields[] = "numero_poliza_seguro = :numero_poliza_seguro";
        $params[':numero_poliza_seguro'] = $input['numero_poliza_seguro'];
    }
    if (isset($input['vencimiento_seguro'])) {
        $updateFields[] = "vencimiento_seguro = :vencimiento_seguro";
        $params[':vencimiento_seguro'] = $input['vencimiento_seguro'];
    }

    // Always update actualizado_en
    $updateFields[] = "actualizado_en = NOW()";

    if (empty($updateFields)) {
        throw new Exception('No hay datos para actualizar');
    }

    // Check if detalles_conductor exists
    $checkDetallesQuery = "SELECT id FROM detalles_conductor WHERE usuario_id = :usuario_id";
    $checkDetallesStmt = $db->prepare($checkDetallesQuery);
    $checkDetallesStmt->bindParam(':usuario_id', $conductor_id, PDO::PARAM_INT);
    $checkDetallesStmt->execute();

    if ($checkDetallesStmt->rowCount() > 0) {
        // Update existing record
        $updateQuery = "UPDATE detalles_conductor SET " . implode(", ", $updateFields) . " WHERE usuario_id = :usuario_id";
        $updateStmt = $db->prepare($updateQuery);
        
        foreach ($params as $key => $value) {
            $updateStmt->bindValue($key, $value);
        }
        
        $updateStmt->execute();
    } else {
        // Insert new record
        $requiredFields = [
            'licencia_conduccion' => '',
            'licencia_vencimiento' => date('Y-m-d', strtotime('+1 year')),
            'vehiculo_tipo' => 'moto',
            'vehiculo_placa' => ''
        ];

        foreach ($requiredFields as $field => $default) {
            $paramKey = ':' . $field;
            if (!isset($params[$paramKey])) {
                $params[$paramKey] = $default;
            }
        }

        $insertQuery = "INSERT INTO detalles_conductor (
            usuario_id, licencia_conduccion, licencia_vencimiento, 
            vehiculo_tipo, vehiculo_marca, vehiculo_modelo, vehiculo_anio, 
            vehiculo_color, vehiculo_placa, aseguradora, numero_poliza_seguro, 
            vencimiento_seguro, creado_en, actualizado_en
        ) VALUES (
            :usuario_id, :licencia_conduccion, :licencia_vencimiento,
            :vehiculo_tipo, 
            " . (isset($params[':vehiculo_marca']) ? ':vehiculo_marca' : 'NULL') . ",
            " . (isset($params[':vehiculo_modelo']) ? ':vehiculo_modelo' : 'NULL') . ",
            " . (isset($params[':vehiculo_anio']) ? ':vehiculo_anio' : 'NULL') . ",
            " . (isset($params[':vehiculo_color']) ? ':vehiculo_color' : 'NULL') . ",
            :vehiculo_placa,
            " . (isset($params[':aseguradora']) ? ':aseguradora' : 'NULL') . ",
            " . (isset($params[':numero_poliza_seguro']) ? ':numero_poliza_seguro' : 'NULL') . ",
            " . (isset($params[':vencimiento_seguro']) ? ':vencimiento_seguro' : 'NULL') . ",
            NOW(), NOW()
        )";

        $insertStmt = $db->prepare($insertQuery);
        
        foreach ($params as $key => $value) {
            $insertStmt->bindValue($key, $value);
        }
        
        $insertStmt->execute();
    }

    // Update user photo if provided
    if (isset($input['foto_perfil'])) {
        $updateUserQuery = "UPDATE usuarios SET foto_perfil = :foto_perfil WHERE id = :id";
        $updateUserStmt = $db->prepare($updateUserQuery);
        $updateUserStmt->bindParam(':foto_perfil', $input['foto_perfil']);
        $updateUserStmt->bindParam(':id', $conductor_id, PDO::PARAM_INT);
        $updateUserStmt->execute();
    }

    $db->commit();

    // Return updated profile
    $profileQuery = "SELECT 
                        u.id,
                        u.nombre,
                        u.apellido,
                        u.email,
                        u.telefono,
                        u.foto_perfil,
                        dc.licencia_conduccion,
                        dc.licencia_vencimiento,
                        dc.vehiculo_tipo,
                        dc.vehiculo_marca,
                        dc.vehiculo_modelo,
                        dc.vehiculo_anio,
                        dc.vehiculo_color,
                        dc.vehiculo_placa,
                        dc.aseguradora,
                        dc.numero_poliza_seguro,
                        dc.vencimiento_seguro,
                        dc.calificacion_promedio,
                        dc.total_viajes,
                        dc.disponible,
                        dc.estado_verificacion
                     FROM usuarios u
                     LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
                     WHERE u.id = :conductor_id";
    
    $profileStmt = $db->prepare($profileQuery);
    $profileStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $profileStmt->execute();

    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Perfil actualizado exitosamente',
        'profile' => $profile
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
