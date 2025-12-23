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
    
    // Validar que se recibió el ID de la solicitud
    if (!isset($data['solicitud_id'])) {
        throw new Exception('El ID de la solicitud es requerido');
    }
    
    $solicitudId = $data['solicitud_id'];
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar que la solicitud existe y está en estado cancelable
    $stmt = $db->prepare("
        SELECT ss.id, ss.estado, ss.cliente_id, ac.conductor_id
        FROM solicitudes_servicio ss
        LEFT JOIN asignaciones_conductor ac ON ss.id = ac.solicitud_id AND ac.estado = 'asignado'
        WHERE ss.id = ?
    ");
    $stmt->execute([$solicitudId]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitud) {
        throw new Exception('Solicitud no encontrada');
    }
    
    // Verificar que la solicitud puede ser cancelada
    // Estados cancelables: pendiente, aceptada, conductor_asignado
    $estadosCancelables = ['pendiente', 'aceptada', 'conductor_asignado'];
    if (!in_array($solicitud['estado'], $estadosCancelables)) {
        throw new Exception('La solicitud no puede ser cancelada en su estado actual: ' . $solicitud['estado']);
    }
    
    // Si hay un conductor asignado, liberarlo y actualizar la asignación
    if ($solicitud['conductor_id']) {
        // Marcar la asignación como cancelada
        $stmt = $db->prepare("
            UPDATE asignaciones_conductor 
            SET estado = 'cancelado'
            WHERE solicitud_id = ? AND conductor_id = ? AND estado = 'asignado'
        ");
        $stmt->execute([$solicitudId, $solicitud['conductor_id']]);
        
        // Liberar al conductor
        $stmt = $db->prepare("
            UPDATE detalles_conductor 
            SET disponible = 1 
            WHERE usuario_id = ?
        ");
        $stmt->execute([$solicitud['conductor_id']]);
    }
    
    // Actualizar el estado de la solicitud a cancelada
    $stmt = $db->prepare("
        UPDATE solicitudes_servicio 
        SET estado = 'cancelada',
            cancelado_en = NOW(),
            motivo_cancelacion = 'Cancelado por el cliente'
        WHERE id = ?
    ");
    $stmt->execute([$solicitudId]);
    
    // Verificar que se actualizó correctamente
    if ($stmt->rowCount() === 0) {
        throw new Exception('No se pudo actualizar el estado de la solicitud');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Solicitud cancelada exitosamente',
        'solicitud_id' => $solicitudId
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
