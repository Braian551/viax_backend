<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $empresa_id = 1;
    echo "=== CONDUCTORES Y SOLICITUDES PARA EMPRESA $empresa_id ===\n";
    
    $stmt = $db->prepare("
        SELECT u.id, u.nombre, u.apellido, u.email, u.es_verificado, u.es_activo, dc.estado_verificacion, dc.aprobado
        FROM usuarios u
        LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE u.empresa_id = :eid OR u.id IN (SELECT conductor_id FROM solicitudes_vinculacion_conductor WHERE empresa_id = :eid)
    ");
    $stmt->execute([':eid' => $empresa_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $r) {
        echo "ID: {$r['id']} | Name: {$r['nombre']} {$r['apellido']} | Email: {$r['email']} | Ver: {$r['es_verificado']} | Act: {$r['es_activo']} | Stat: {$r['estado_verificacion']} | Apr: {$r['aprobado']}\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
