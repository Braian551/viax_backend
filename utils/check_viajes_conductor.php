<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();
    
    echo "=== SOLICITUDES COMPLETADAS (SAMPLE) ===\n";
    $r = $db->query("SELECT id, conductor_id, cliente_id, estado FROM solicitudes_servicio WHERE estado = 'completada' LIMIT 10");
    print_r($r->fetchAll(PDO::FETCH_ASSOC));
    
    echo "\n=== COLUMNAS DE solicitudes_servicio ===\n";
    $r = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'solicitudes_servicio' AND column_name LIKE '%conductor%'");
    print_r($r->fetchAll(PDO::FETCH_COLUMN));
    
    echo "\n=== ASIGNACIONES_CONDUCTOR TOTAL ===\n";
    $r = $db->query("SELECT COUNT(*) as total FROM asignaciones_conductor");
    echo "Total asignaciones: " . $r->fetchColumn() . "\n";
    
    echo "\n=== ASIGNACIONES DE OSCAR (277) ===\n";
    $r = $db->query("SELECT ac.id, ac.solicitud_id, ac.conductor_id, ac.estado, s.estado as solicitud_estado 
                     FROM asignaciones_conductor ac 
                     LEFT JOIN solicitudes_servicio s ON ac.solicitud_id = s.id 
                     WHERE ac.conductor_id = 277 LIMIT 10");
    print_r($r->fetchAll(PDO::FETCH_ASSOC));
    
    echo "\n=== DETALLES_CONDUCTOR DE OSCAR (277) ===\n";
    $r = $db->query("SELECT usuario_id, total_viajes, calificacion_promedio, total_calificaciones FROM detalles_conductor WHERE usuario_id = 277");
    print_r($r->fetch(PDO::FETCH_ASSOC));
    
    echo "\n=== VIAJES COMPLETADOS POR EMPRESA (via asignaciones_conductor) ===\n";
    $query = "
        SELECT 
            u.empresa_id,
            e.nombre as empresa_nombre,
            COUNT(DISTINCT ac.solicitud_id) as viajes_completados
        FROM asignaciones_conductor ac
        JOIN usuarios u ON ac.conductor_id = u.id
        JOIN empresas_transporte e ON u.empresa_id = e.id
        JOIN solicitudes_servicio s ON ac.solicitud_id = s.id
        WHERE s.estado = 'completada'
        AND u.empresa_id IS NOT NULL
        GROUP BY u.empresa_id, e.nombre
    ";
    $r = $db->query($query);
    print_r($r->fetchAll(PDO::FETCH_ASSOC));
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
