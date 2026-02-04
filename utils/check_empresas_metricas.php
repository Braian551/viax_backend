<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();
    
    echo "=== ESTRUCTURA DE empresas_metricas ===\n";
    $r = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'empresas_metricas' ORDER BY ordinal_position");
    $columns = $r->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo "  - {$col['column_name']}: {$col['data_type']}\n";
    }
    
    echo "\n=== DATOS ACTUALES EN empresas_metricas ===\n";
    $r = $db->query("SELECT * FROM empresas_metricas");
    $metricas = $r->fetchAll(PDO::FETCH_ASSOC);
    print_r($metricas);
    
    echo "\n=== VIAJES COMPLETADOS POR EMPRESA (CALCULADO) ===\n";
    $query = "
        SELECT 
            u.empresa_id,
            e.nombre as empresa_nombre,
            COUNT(DISTINCT s.id) as viajes_completados
        FROM solicitudes_servicio s
        JOIN usuarios u ON s.conductor_id = u.id
        JOIN empresas_transporte e ON u.empresa_id = e.id
        WHERE s.estado IN ('completado', 'finalizado')
        AND u.empresa_id IS NOT NULL
        GROUP BY u.empresa_id, e.nombre
    ";
    $r = $db->query($query);
    print_r($r->fetchAll(PDO::FETCH_ASSOC));
    
    echo "\n=== CALIFICACIONES PROMEDIO POR EMPRESA (CALCULADO) ===\n";
    $query = "
        SELECT 
            u.empresa_id,
            e.nombre as empresa_nombre,
            AVG(c.calificacion) as calificacion_promedio,
            COUNT(c.id) as total_calificaciones
        FROM calificaciones c
        JOIN usuarios u ON c.usuario_calificado_id = u.id
        JOIN empresas_transporte e ON u.empresa_id = e.id
        WHERE u.empresa_id IS NOT NULL
        AND u.tipo_usuario = 'conductor'
        GROUP BY u.empresa_id, e.nombre
    ";
    $r = $db->query($query);
    print_r($r->fetchAll(PDO::FETCH_ASSOC));
    
    echo "\n=== CONDUCTOR OSCAR (277) EMPRESA_ID CHECK ===\n";
    $r = $db->query("SELECT id, nombre, email, empresa_id, tipo_usuario FROM usuarios WHERE id = 277");
    print_r($r->fetch(PDO::FETCH_ASSOC));
    
    echo "\n=== VIAJES DE OSCAR (277) ===\n";
    $r = $db->query("SELECT COUNT(*) as total, estado FROM solicitudes_servicio WHERE conductor_id = 277 GROUP BY estado");
    print_r($r->fetchAll(PDO::FETCH_ASSOC));
    
    echo "\n=== TODOS LOS ESTADOS DE SOLICITUDES ===\n";
    $r = $db->query("SELECT estado, COUNT(*) as total FROM solicitudes_servicio GROUP BY estado ORDER BY total DESC");
    print_r($r->fetchAll(PDO::FETCH_ASSOC));
    
    echo "\n=== VIAJES CON conductor_id NOT NULL ===\n";
    $r = $db->query("SELECT estado, COUNT(*) as total FROM solicitudes_servicio WHERE conductor_id IS NOT NULL GROUP BY estado ORDER BY total DESC");
    print_r($r->fetchAll(PDO::FETCH_ASSOC));
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
