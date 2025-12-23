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
    $disponible = isset($input['disponible']) ? intval($input['disponible']) : 0;
    $latitud = isset($input['latitud']) ? floatval($input['latitud']) : null;
    $longitud = isset($input['longitud']) ? floatval($input['longitud']) : null;

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Verificar si existe registro en detalles_conductor
    $query_check = "SELECT id, aprobado, estado_verificacion FROM detalles_conductor WHERE usuario_id = :conductor_id";
    $stmt_check = $db->prepare($query_check);
    $stmt_check->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_check->execute();
    $existe = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($existe) {
        // Si el conductor intenta activar la disponibilidad, verificar que esté aprobado
        if ($disponible == 1) {
            $aprobado = intval($existe['aprobado']);
            $estado_verificacion = $existe['estado_verificacion'];
            
            if ($aprobado != 1 || $estado_verificacion != 'aprobado') {
                throw new Exception('Tu perfil debe estar aprobado por un administrador antes de poder activar la disponibilidad. Por favor espera la aprobación o completa los documentos pendientes.');
            }
        }
        
        // Actualizar registro existente
        $query = "UPDATE detalles_conductor 
                  SET disponible = :disponible";
        
        if ($latitud !== null && $longitud !== null) {
            $query .= ", latitud_actual = :latitud, longitud_actual = :longitud";
        }
        
        $query .= " WHERE usuario_id = :conductor_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':disponible', $disponible, PDO::PARAM_INT);
        $stmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
        
        if ($latitud !== null && $longitud !== null) {
            $stmt->bindParam(':latitud', $latitud);
            $stmt->bindParam(':longitud', $longitud);
        }
        
        $stmt->execute();
    } else {
        // No existe registro en detalles_conductor - el conductor debe completar su perfil primero
        throw new Exception('El conductor debe completar su perfil antes de poder cambiar su disponibilidad. Registre su licencia, vehículo y documentos requeridos.');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Disponibilidad actualizada exitosamente',
        'disponible' => $disponible === 1
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
