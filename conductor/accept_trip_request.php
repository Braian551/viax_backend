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
    
    $database = new Database();
    $db = $database->getConnection();
    $db->beginTransaction();
    
    try {
        // Verificar que la solicitud existe y está pendiente
        $stmt = $db->prepare("
            SELECT id, estado, cliente_id, tipo_servicio 
            FROM solicitudes_servicio 
            WHERE id = ? 
            FOR UPDATE
        ");
        $stmt->execute([$solicitudId]);
        $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$solicitud) {
            throw new Exception('Solicitud no encontrada');
        }
        
        if ($solicitud['estado'] !== 'pendiente') {
            throw new Exception('La solicitud ya fue aceptada por otro conductor');
        }
        
        // Verificar que el conductor esté disponible
        $stmt = $db->prepare("
            SELECT u.id, dc.disponible, dc.vehiculo_tipo
            FROM usuarios u
            INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
            WHERE u.id = ? 
            AND u.tipo_usuario = 'conductor'
            AND dc.estado_verificacion = 'aprobado'
        ");
        $stmt->execute([$conductorId]);
        $conductor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$conductor) {
            throw new Exception('Conductor no encontrado o no verificado');
        }
        
        if (!$conductor['disponible']) {
            throw new Exception('Conductor no disponible');
        }
        
        // Verificar compatibilidad de vehículo según el tipo de servicio
        // Para transporte, cualquier vehículo aprobado puede servir
        // Para envío de paquetes, verificar tipo de vehículo si es necesario
        if ($solicitud['tipo_servicio'] === 'envio_paquete') {
            // Para paquetes, preferir furgonetas o carros, pero permitir motocicletas para paquetes pequeños
            $vehiculosPermitidos = ['auto', 'furgoneta', 'moto'];
            if (!in_array($conductor['vehiculo_tipo'], $vehiculosPermitidos)) {
                throw new Exception('Tipo de vehículo no compatible con el servicio de envío');
            }
        }
        // Para transporte, cualquier vehículo aprobado está bien
        
        // Actualizar la solicitud
        $stmt = $db->prepare("
            UPDATE solicitudes_servicio 
            SET estado = 'aceptada',
                aceptado_en = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$solicitudId]);
        
        // Crear asignación
        $stmt = $db->prepare("
            INSERT INTO asignaciones_conductor (
                solicitud_id, 
                conductor_id, 
                asignado_en, 
                estado
            ) VALUES (?, ?, NOW(), 'asignado')
        ");
        $stmt->execute([$solicitudId, $conductorId]);
        
        // Actualizar disponibilidad del conductor
        $stmt = $db->prepare("
            UPDATE detalles_conductor 
            SET disponible = 0 
            WHERE usuario_id = ?
        ");
        $stmt->execute([$conductorId]);
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Viaje aceptado exitosamente',
            'solicitud_id' => $solicitudId
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
