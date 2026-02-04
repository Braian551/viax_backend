<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $empresa_id = 1;

    echo "=== DEBUG DASHBOARD_STATS CONDUCTOR COUNT ===\n";

    // 1. Verify user 277 again
    echo "USER 277:\n";
    $stmt = $db->prepare("SELECT id, nombre, tipo_usuario, empresa_id, es_activo FROM usuarios WHERE id = 277");
    $stmt->execute();
    print_r($stmt->fetch(PDO::FETCH_ASSOC));

    // 2. Run conductors_sql from dashboard_stats.php
    $conductores_sql = "SELECT 
                         COUNT(*) as total,
                         COUNT(CASE WHEN es_activo = 1 THEN 1 END) as activos,
                         COUNT(CASE WHEN es_activo = 0 THEN 1 END) as inactivos
                        FROM usuarios 
                        WHERE tipo_usuario = 'conductor' 
                        AND empresa_id = :empresa_id";
    
    $stmt = $db->prepare($conductores_sql);
    $stmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $stmt->execute();
    $conductores_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "CONDUCTORES STATS (empresa_id = $empresa_id):\n";
    print_r($conductores_stats);

    // 3. Count all users with tipo_usuario = 'conductor'
    echo "ALL CONDUCTORS:\n";
    $stmt = $db->query("SELECT id, nombre, empresa_id, tipo_usuario FROM usuarios WHERE tipo_usuario = 'conductor'");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
