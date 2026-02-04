<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $conductor_id = 277;
    $empresa_id = 1;

    echo "=== DATABASE STATE AFTER APPROVAL ===\n";

    // Check usuarios table
    $stmt = $db->prepare("SELECT id, nombre, email, empresa_id, es_verificado, es_activo FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $conductor_id]);
    echo "USUARIOS:\n";
    print_r($stmt->fetch(PDO::FETCH_ASSOC));

    // Check detalles_conductor
    $stmt = $db->prepare("SELECT usuario_id, estado_verificacion, estado_aprobacion, aprobado, licencia_conduccion, vehiculo_placa FROM detalles_conductor WHERE usuario_id = :id");
    $stmt->execute([':id' => $conductor_id]);
    echo "DETALLES_CONDUCTOR:\n";
    print_r($stmt->fetch(PDO::FETCH_ASSOC));

    // Check solicitudes_vinculacion_conductor
    $stmt = $db->prepare("SELECT id, conductor_id, empresa_id, estado FROM solicitudes_vinculacion_conductor WHERE conductor_id = :id AND empresa_id = :eid");
    $stmt->execute([':id' => $conductor_id, ':eid' => $empresa_id]);
    echo "SOLICITUDES:\n";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
