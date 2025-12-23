<?php
require_once __DIR__ . '/config/database.php';

try {
    $db = (new Database())->getConnection();
    echo "Conexión exitosa\n\n";
    
    // Ver estructura de conductor
    $stmt = $db->query("SELECT usuario_id, disponible, latitud_actual, longitud_actual FROM detalles_conductor LIMIT 5");
    $conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Conductores:\n";
    print_r($conductores);
    
    // Simular la consulta del get_demand_zones
    echo "\n\nProbando query de zonas de demanda...\n";
    
    $minLat = 6.0;
    $maxLat = 6.5;
    $minLng = -76.0;
    $maxLng = -75.0;
    
    $driversQuery = "
        SELECT 
            dc.latitud_actual,
            dc.longitud_actual,
            dc.vehiculo_tipo
        FROM detalles_conductor dc
        INNER JOIN usuarios u ON dc.usuario_id = u.id
        WHERE dc.disponible = 1
        AND dc.estado_verificacion = 'aprobado'
        AND dc.latitud_actual BETWEEN ? AND ?
        AND dc.longitud_actual BETWEEN ? AND ?
        AND dc.ultima_actualizacion >= NOW() - INTERVAL '10 minutes'
    ";
    
    $stmt = $db->prepare($driversQuery);
    $stmt->execute([$minLat, $maxLat, $minLng, $maxLng]);
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Drivers encontrados: " . count($drivers) . "\n";
    print_r($drivers);
    
    echo "\nTodo OK!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
