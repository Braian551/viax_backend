<?php
/**
 * Script de utilidad para resetear el estado de un conductor para pruebas.
 * Uso: php reset_test_conductor.php [conductor_id] [empresa_id]
 */

require_once __DIR__ . '/../config/database.php';

$conductor_id = isset($argv[1]) ? intval($argv[1]) : 277; // Default from logs
$empresa_id = isset($argv[2]) ? intval($argv[2]) : 1;     // Default from logs

echo "Resetear conductor ID: $conductor_id para Empresa ID: $empresa_id\n";

try {
    $database = new Database();
    $db = $database->getConnection();

    $db->beginTransaction();

    // 1. Resetear solicitud de vinculación si existe
    echo "1. Reseteando solicitud de vinculación...\n";
    $query1 = "UPDATE solicitudes_vinculacion_conductor 
               SET estado = 'pendiente', 
                   procesado_por = NULL, 
                   procesado_en = NULL,
                   razon_rechazo = NULL
               WHERE conductor_id = :cid AND empresa_id = :eid";
    $stmt1 = $db->prepare($query1);
    $stmt1->execute([':cid' => $conductor_id, ':eid' => $empresa_id]);

    // 2. Resetear detalles_conductor
    echo "2. Reseteando detalles_conductor...\n";
    $query2 = "UPDATE detalles_conductor 
               SET estado_verificacion = 'pendiente',
                   estado_aprobacion = 'pendiente', 
                   aprobado = 0,
                   razon_rechazo = NULL
               WHERE usuario_id = :cid";
    $stmt2 = $db->prepare($query2);
    $stmt2->execute([':cid' => $conductor_id]);

    // 3. Resetear usuario (es_verificado, es_activo)
    echo "3. Reseteando usuario...\n";
    $query3 = "UPDATE usuarios 
               SET es_verificado = 0, es_activo = 0
               WHERE id = :cid";
    $stmt3 = $db->prepare($query3);
    $stmt3->execute([':cid' => $conductor_id]);

    $db->commit();
    echo "✅ EXITO: Conductor $conductor_id reseteado a estado pendiente.\n";

} catch (Exception $e) {
    $db->rollBack();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
