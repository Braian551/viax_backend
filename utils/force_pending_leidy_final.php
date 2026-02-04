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
            estado_verificacion = 'pendiente',
            razon_rechazo = NULL,
            aprobado = 0
        WHERE usuario_id = :id
    ");
    $stmt1->execute([':id' => $conductor_id]);
    echo "detalles_conductor actualizado: " . $stmt1->rowCount() . " filas\n";
    
    // 2. Actualizar solicitudes_vinculacion_conductor
    $stmt2 = $db->prepare("
        UPDATE solicitudes_vinculacion_conductor 
        SET estado = 'pendiente', 
            respuesta_empresa = NULL,
            procesado_en = NULL,
            procesado_por = NULL,
            creado_en = NOW()
        WHERE conductor_id = :id
    ");
    $stmt2->execute([':id' => $conductor_id]);
    echo "solicitudes_vinculacion_conductor actualizado: " . $stmt2->rowCount() . " filas\n";
    
    // 3. Resetear usuarios
    $stmt3 = $db->prepare("UPDATE usuarios SET es_verificado = 0, es_activo = 1 WHERE id = :id");
    $stmt3->execute([':id' => $conductor_id]);
    echo "usuarios actualizado: " . $stmt3->rowCount() . " filas\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
