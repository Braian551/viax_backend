<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $conductor_id = 278; // ID de Leidy Andrea
    
    // 1. Actualizar detalles_conductor
    // Nota: estado_verificacion es la columna usada en get_conductores_documentos.php
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
        WHERE conductor_id = :id
    ");
    $stmt2->execute([':id' => $conductor_id]);
    $affected2 = $stmt2->rowCount();
    
    // 3. También verificar la tabla usuarios (por si acaso)
    $stmt3 = $db->prepare("UPDATE usuarios SET es_verificado = 0 WHERE id = :id");
    $stmt3->execute([':id' => $conductor_id]);
    
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
