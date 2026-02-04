<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $conductor_id = 278; // ID de Leidy Andrea
    
    // 1. Actualizar detalles_conductor
    $stmt1 = $db->prepare("
        UPDATE detalles_conductor 
        SET estado_aprobacion = 'pendiente', 
            razon_rechazo = NULL,
            estado_verificacion = 'pendiente'
        WHERE usuario_id = :id
    ");
    $stmt1->execute([':id' => $conductor_id]);
    $affected1 = $stmt1->rowCount();
    
    // 2. Actualizar solicitudes_vinculacion_conductor
    $stmt2 = $db->prepare("
        UPDATE solicitudes_vinculacion_conductor 
        SET estado = 'pendiente', 
            motivo_rechazo = NULL,
            actualizado_en = NOW()
        WHERE conductor_id = :id AND estado = 'rechazada'
    ");
    $stmt2->execute([':id' => $conductor_id]);
    $affected2 = $stmt2->rowCount();
    
    echo json_encode([
        'success' => true,
        'message' => "Estado de Leidy (ID $conductor_id) actualizado a pendiente.",
        'details' => [
            'detalles_conductor_updated' => $affected1,
            'solicitudes_updated' => $affected2
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
