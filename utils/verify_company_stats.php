<?php
// Script to verify company stats API
require_once __DIR__ . '/../config/database.php';

// Url of the service (local simulation)
// We will just require the file and mock input/output interception?
// Easier to doing a curl request to localhost if running? 
// Or just replicate the logic to seeing if the query works.

// Let's just execute the query logic directly to see what DB returns.
// This is quicker than mocking HTTP environment.

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get Elite ID
    $stmt = $conn->prepare("SELECT id FROM empresas_transporte WHERE nombre LIKE 'Elite%' LIMIT 1");
    $stmt->execute();
    $elite = $stmt->fetch(PDO::FETCH_ASSOC);
    $empresaId = $elite['id'];
    
    echo "Checking stats for Company ID: $empresaId\n";

    // 1. Conductores
    $sql = "SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'conductor' AND empresa_id = $empresaId AND es_activo = 1";
    echo "Drivers: " . $conn->query($sql)->fetchColumn() . "\n";

    // 2. Viajes
    $sql = "SELECT COUNT(ss.id) 
                FROM solicitudes_servicio ss
                JOIN asignaciones_conductor ac ON ss.id = ac.solicitud_id
                JOIN usuarios u ON ac.conductor_id = u.id
                WHERE u.empresa_id = $empresaId
                AND ss.estado = 'completada'";
    echo "Trips: " . $conn->query($sql)->fetchColumn() . "\n";

    // 3. Rating
    $sql = "SELECT AVG(calificacion) FROM calificaciones c JOIN usuarios u ON c.usuario_calificado_id = u.id WHERE u.empresa_id = $empresaId";
    echo "Rating: " . $conn->query($sql)->fetchColumn() . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
