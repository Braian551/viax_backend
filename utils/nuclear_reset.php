<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $conductor_id = 277;
    $empresa_id = 1;

    echo "=== NUCLEAR RESET CONDUCTOR $conductor_id ===\n";

    $db->beginTransaction();

    // 1. Resetear Usuario: Quitar vinculacion, verificacion y activar
    $db->prepare("UPDATE usuarios SET empresa_id = NULL, es_verificado = 0, es_activo = 0 WHERE id = ?")
       ->execute([$conductor_id]);
    echo "1. Usuario reseteado (empresa_id = NULL, es_verificado = 0, es_activo = 0)\n";

    // 2. Resetear Detalles Conductor: Marcar como pendiente
    // Verificamos si existe primero
    $stmt = $db->prepare("SELECT COUNT(*) FROM detalles_conductor WHERE usuario_id = ?");
    $stmt->execute([$conductor_id]);
    if ($stmt->fetchColumn() > 0) {
        $db->prepare("UPDATE detalles_conductor SET estado_verificacion = 'pendiente', estado_aprobacion = 'pendiente', aprobado = 0, actualizado_en = NOW() WHERE usuario_id = ?")
           ->execute([$conductor_id]);
        echo "2. detalles_conductor actualizados a 'pendiente'\n";
    } else {
        echo "2. detalles_conductor no existe (esto está bien para el reset)\n";
    }

    // 3. Solicitud de Vinculación: Asegurarnos que existe UNA y está PENDIENTE
    // Eliminamos solicitudes viejas para este par para limpiar
    $db->prepare("DELETE FROM solicitudes_vinculacion_conductor WHERE conductor_id = ? AND empresa_id = ?")
       ->execute([$conductor_id, $empresa_id]);
    
    // Insertamos una nueva limpia
    $db->prepare("INSERT INTO solicitudes_vinculacion_conductor (conductor_id, empresa_id, estado, creado_en) VALUES (?, ?, 'pendiente', NOW())")
       ->execute([$conductor_id, $empresa_id]);
    echo "3. Solicitud de vinculación reseteada (Nueva y PENDIENTE)\n";

    $db->commit();
    echo "✅ EXITOSAMENTE NUCLEARIZADO\n";

    echo "\n=== VERIFICACIÓN FINAL (LECTURA DE DB) ===\n";
    $u = $db->prepare("SELECT id, empresa_id, es_verificado FROM usuarios WHERE id = ?");
    $u->execute([$conductor_id]);
    print_r($u->fetch(PDO::FETCH_ASSOC));

    $d = $db->prepare("SELECT estado_verificacion, aprobado FROM detalles_conductor WHERE usuario_id = ?");
    $d->execute([$conductor_id]);
    print_r($d->fetch(PDO::FETCH_ASSOC));

    $s = $db->prepare("SELECT id, estado FROM solicitudes_vinculacion_conductor WHERE conductor_id = ? AND empresa_id = ?");
    $s->execute([$conductor_id, $empresa_id]);
    print_r($s->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    if (isset($db)) $db->rollBack();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
