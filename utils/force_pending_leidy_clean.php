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
    
    // 2. Limpiar solicitudes duplicadas o conflictivas y dejar solo una pendiente
    // Primero, vemos qué hay
    $stmt = $db->prepare("SELECT id, estado, empresa_id FROM solicitudes_vinculacion_conductor WHERE conductor_id = :id");
    $stmt->execute([':id' => $conductor_id]);
    $solicitudes = $stmt->fetchAll();
    
    echo "Solicitudes encontradas: " . count($solicitudes) . "\n";
    foreach($solicitudes as $s) {
        echo "- ID: {$s['id']}, Estado: {$s['estado']}, Empresa: {$s['empresa_id']}\n";
    }

    // Borramos todas para resetear limpio (solo para este caso de prueba)
    $db->prepare("DELETE FROM solicitudes_vinculacion_conductor WHERE conductor_id = :id")->execute([':id' => $conductor_id]);
    echo "Solicitudes antiguas borradas.\n";

    // Insertamos la nueva limpia
    // Necesitamos el empresa_id de Leidy
    $stmt = $db->prepare("SELECT empresa_id FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $conductor_id]);
    $empresa_id = $stmt->fetchColumn();
    
    if (!$empresa_id) $empresa_id = 1; // Default fallback

    $stmt2 = $db->prepare("
        INSERT INTO solicitudes_vinculacion_conductor (conductor_id, empresa_id, estado, creado_en)
        VALUES (:cid, :eid, 'pendiente', NOW())
    ");
    $stmt2->execute([':cid' => $conductor_id, ':eid' => $empresa_id]);
    echo "Nueva solicitud pendiente insertada para empresa $empresa_id.\n";
    
    // 3. Resetear usuarios
    $stmt3 = $db->prepare("UPDATE usuarios SET es_verificado = 0, es_activo = 1 WHERE id = :id");
    $stmt3->execute([':id' => $conductor_id]);
    echo "usuarios actualizado: " . $stmt3->rowCount() . " filas\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
