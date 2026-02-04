<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $empresa_id = 1;
    $conductor_id = 277;

    echo "=== DEBUG DRIVERS.PHP QUERY ===\n";

    // Check usuario record
    $stmt = $db->prepare("SELECT id, nombre, email, tipo_usuario, empresa_id, es_activo, es_verificado FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $conductor_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "USUARIO 277:\n";
    print_r($user);

    // Now run the actual query from drivers.php
    $query = "SELECT 
                u.id, u.nombre, u.apellido, u.email, u.telefono, 
                u.foto_perfil, u.es_activo, u.es_verificado,
                u.fecha_registro, u.empresa_id, u.tipo_usuario,
                d.estado_verificacion
              FROM usuarios u
              LEFT JOIN detalles_conductor d ON u.id = d.usuario_id
              WHERE u.tipo_usuario = 'conductor' 
              AND u.empresa_id = :empresa_id
              ORDER BY u.fecha_registro DESC";
              
    $stmt = $db->prepare($query);
    $stmt->execute([':empresa_id' => $empresa_id]);
    $conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "CONDUCTORES FROM QUERY (empresa_id = $empresa_id):\n";
    print_r($conductores);
    echo "Total: " . count($conductores) . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
