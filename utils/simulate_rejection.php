<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $conductor_id = 254;
    $reason = "Documento de identidad borroso y licencia vencida.";

    echo "=== SIMULATING REJECTION FOR ID $conductor_id ===\n";

    // Ensure record exists or insert
    $check = $db->query("SELECT id FROM detalles_conductor WHERE usuario_id = $conductor_id");
    if (!$check->fetch()) {
        $db->query("INSERT INTO detalles_conductor (usuario_id, estado_aprobacion) VALUES ($conductor_id, 'rechazado')");
    }

    $stmt = $db->prepare("UPDATE detalles_conductor SET estado_aprobacion = 'rechazado', razon_rechazo = :reason WHERE usuario_id = :id");
    $stmt->bindParam(':reason', $reason);
    $stmt->bindParam(':id', $conductor_id);
    $stmt->execute();

    echo "Status updated to 'rechazado'. Reason set.\n";

    // Verify
    $stmt = $db->query("SELECT estado_aprobacion, razon_rechazo FROM detalles_conductor WHERE usuario_id = $conductor_id");
    print_r($stmt->fetch(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
