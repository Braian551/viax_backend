<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $empresa_id = 1;

    echo "=== REFINED CONDUCTOR COUNT ===\n";

    $query = "SELECT 
                u.id, u.nombre, u.email, u.tipo_usuario, u.empresa_id, u.es_activo
              FROM usuarios u
              WHERE 
              ((u.tipo_usuario = 'conductor' AND u.empresa_id = :empresa_id)
               OR u.id IN (SELECT conductor_id FROM solicitudes_vinculacion_conductor WHERE empresa_id = :empresa_id_sv AND estado = 'pendiente'))";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $stmt->bindParam(':empresa_id_sv', $empresa_id, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $u) {
        echo "ID: {$u['id']}, Name: {$u['nombre']}, Type: {$u['tipo_usuario']}, EmpresaID: {$u['empresa_id']}, Active: {$u['es_activo']}\n";
    }
    echo "Total: " . count($users) . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
