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

    $input = json_decode(file_get_contents('php://input'), true);

    $conductor_id = isset($input['conductor_id']) ? intval($input['conductor_id']) : 0;
    $latitud = isset($input['latitud']) ? floatval($input['latitud']) : null;
    $longitud = isset($input['longitud']) ? floatval($input['longitud']) : null;

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    if ($latitud === null || $longitud === null) {
        throw new Exception('Latitud y longitud son requeridas');
    }

    // Actualizar ubicación en detalles_conductor
    $query = "UPDATE detalles_conductor 
              SET latitud_actual = :latitud, 
                  longitud_actual = :longitud,
                  ultima_actualizacion = NOW()
              WHERE usuario_id = :conductor_id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':latitud', $latitud);
    $stmt->bindParam(':longitud', $longitud);
    $stmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt->execute();

    // Actualizar detalles_conductor (existente)
    if ($stmt->rowCount() === 0) {
        // Si no existe, crear registro
        $query_insert = "INSERT INTO detalles_conductor 
                         (usuario_id, latitud_actual, longitud_actual, fecha_creacion, ultima_actualizacion)
                         VALUES (:conductor_id, :latitud, :longitud, NOW(), NOW())";
        
        $stmt_insert = $db->prepare($query_insert);
        $stmt_insert->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
        $stmt_insert->bindParam(':latitud', $latitud);
        $stmt_insert->bindParam(':longitud', $longitud);
        $stmt_insert->execute();
    }

    // --- NUEVO: Actualizar datos del viaje activo si se proporcionan ---
    $solicitud_id = isset($input['solicitud_id']) ? intval($input['solicitud_id']) : 0;
    $distancia_recorrida = isset($input['distancia_recorrida']) ? floatval($input['distancia_recorrida']) : null;
    $tiempo_transcurrido = isset($input['tiempo_transcurrido']) ? intval($input['tiempo_transcurrido']) : null;

    if ($solicitud_id > 0 && ($distancia_recorrida !== null || $tiempo_transcurrido !== null)) {
        $update_parts = [];
        $params = [':solicitud_id' => $solicitud_id];

        if ($distancia_recorrida !== null) {
            $update_parts[] = "distancia_recorrida = :distancia";
            $params[':distancia'] = $distancia_recorrida;
        }

        if ($tiempo_transcurrido !== null) {
            $update_parts[] = "tiempo_transcurrido = :tiempo";
            $params[':tiempo'] = $tiempo_transcurrido;
        }

        if (!empty($update_parts)) {
            $sql_trip = "UPDATE solicitudes_servicio SET " . implode(', ', $update_parts) . " WHERE id = :solicitud_id";
            $stmt_trip = $db->prepare($sql_trip);
            $stmt_trip->execute($params);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Ubicación actualizada exitosamente'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
