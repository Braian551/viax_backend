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
    
    $solicitudId = $data['solicitud_id'] ?? null;
    $conductorId = $data['conductor_id'] ?? null;
    $nuevoEstado = $data['nuevo_estado'] ?? null;
    
    if (!$solicitudId || !$conductorId || !$nuevoEstado) {
        throw new Exception('solicitud_id, conductor_id y nuevo_estado son requeridos');
    }
    
    // Validar estados permitidos
    $estadosPermitidos = ['aceptada', 'conductor_asignado', 'conductor_llego', 'recogido', 'en_transito', 'en_curso', 'entregado', 'completada', 'cancelada'];
    if (!in_array($nuevoEstado, $estadosPermitidos)) {
        throw new Exception('Estado no válido');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar que el conductor está asignado a esta solicitud
    $stmt = $db->prepare("
        SELECT s.*, ac.conductor_id 
        FROM solicitudes_servicio s
        LEFT JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
        WHERE s.id = ?
    ");
    $stmt->execute([$solicitudId]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitud) {
        throw new Exception('Solicitud no encontrada');
    }
    
    if ($solicitud['conductor_id'] && $solicitud['conductor_id'] != $conductorId) {
        throw new Exception('No tienes permiso para actualizar esta solicitud');
    }
    
    // Actualizar estado y timestamps correspondientes
    $updates = ['estado = ?'];
    $params = [$nuevoEstado];
    
    switch ($nuevoEstado) {
        case 'aceptada':
        case 'conductor_asignado':
            $updates[] = 'aceptado_en = NOW()';
            break;
        case 'conductor_llego':
            // Actualizar también la asignación a estado 'llegado'
            $stmtAsig = $db->prepare("
                UPDATE asignaciones_conductor 
                SET estado = 'llegado'
                WHERE solicitud_id = ? AND conductor_id = ?
            ");
            $stmtAsig->execute([$solicitudId, $conductorId]);
            break;
        case 'recogido':
        case 'en_transito':
        case 'en_curso':
            $updates[] = 'recogido_en = NOW()';
            break;
        case 'entregado':
            $updates[] = 'entregado_en = NOW()';
            // Guardar precio final si se proporciona
            if (isset($data['precio_final']) && $data['precio_final'] > 0) {
                $updates[] = 'precio_final = ?';
                $params[] = $data['precio_final'];
            }
            break;
        case 'completada':
            $updates[] = 'completado_en = NOW()';
            // Guardar precio final si se proporciona
            if (isset($data['precio_final']) && $data['precio_final'] > 0) {
                $updates[] = 'precio_final = ?';
                $params[] = $data['precio_final'];
            }
            break;
        case 'cancelada':
            $updates[] = 'cancelado_en = NOW()';
            if (isset($data['motivo_cancelacion'])) {
                $updates[] = 'motivo_cancelacion = ?';
                $params[] = $data['motivo_cancelacion'];
            }
            break;
    }
    
    $params[] = $solicitudId;
    
    $stmt = $db->prepare("
        UPDATE solicitudes_servicio 
        SET " . implode(', ', $updates) . "
        WHERE id = ?
    ");
    $stmt->execute($params);
    
    // Si se completó el viaje, actualizar disponibilidad del conductor
    if ($nuevoEstado === 'completada') {
        $stmt = $db->prepare("
            UPDATE detalles_conductor 
            SET disponible = 1,
                total_viajes = COALESCE(total_viajes, 0) + 1
            WHERE usuario_id = ?
        ");
        $stmt->execute([$conductorId]);
    }
    
    // Si se canceló, liberar al conductor
    if ($nuevoEstado === 'cancelada') {
        $stmt = $db->prepare("
            UPDATE detalles_conductor 
            SET disponible = 1
            WHERE usuario_id = ?
        ");
        $stmt->execute([$conductorId]);
        
        // Actualizar estado de asignación
        $stmt = $db->prepare("
            UPDATE asignaciones_conductor 
            SET estado = 'cancelado'
            WHERE solicitud_id = ? AND conductor_id = ?
        ");
        $stmt->execute([$solicitudId, $conductorId]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Estado actualizado correctamente',
        'nuevo_estado' => $nuevoEstado
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
