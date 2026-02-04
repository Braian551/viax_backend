<?php
/**
 * Script para sincronizar métricas de empresas.
 * 
 * Recalcula total_viajes_completados, calificacion_promedio, etc.
 * basado en los datos actuales de la base de datos.
 * 
 * Uso: php sync_empresas_metricas.php
 */

require_once __DIR__ . '/../config/database.php';

echo "=== SINCRONIZACIÓN DE MÉTRICAS DE EMPRESAS ===\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = (new Database())->getConnection();
    
    // Obtener todas las empresas
    $empresas = $db->query("SELECT id, nombre FROM empresas_transporte WHERE estado = 'activo'")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Empresas activas encontradas: " . count($empresas) . "\n\n";
    
    foreach ($empresas as $empresa) {
        $empresaId = $empresa['id'];
        $empresaNombre = $empresa['nombre'];
        
        echo "Procesando: {$empresaNombre} (ID: {$empresaId})\n";
        
        // 1. Contar conductores
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_conductores,
                COUNT(CASE WHEN dc.disponible = 1 THEN 1 END) as conductores_activos,
                COUNT(CASE WHEN dc.estado_verificacion = 'pendiente' THEN 1 END) as conductores_pendientes
            FROM usuarios u
            LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
            WHERE u.empresa_id = ? 
            AND u.tipo_usuario = 'conductor'
            AND u.es_activo = 1
        ");
        $stmt->execute([$empresaId]);
        $conductores = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 2. Contar viajes completados (usando asignaciones_conductor)
        // Nota: El estado de la asignación puede ser 'completado', 'llegado', 'en_curso' cuando la solicitud está completada
        $stmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT s.id) as viajes_completados,
                COALESCE(SUM(COALESCE(s.precio_final, s.precio_estimado, 0)), 0) as ingresos_totales
            FROM solicitudes_servicio s
            JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = ?
            AND s.estado = 'completada'
        ");
        $stmt->execute([$empresaId]);
        $viajes = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 3. Viajes cancelados
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT s.id) as viajes_cancelados
            FROM solicitudes_servicio s
            JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = ?
            AND s.estado = 'cancelada'
        ");
        $stmt->execute([$empresaId]);
        $cancelados = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 4. Calificaciones
        $stmt = $db->prepare("
            SELECT 
                COALESCE(AVG(c.calificacion), 0) as promedio,
                COUNT(c.id) as total
            FROM calificaciones c
            JOIN usuarios u ON c.usuario_calificado_id = u.id
            WHERE u.empresa_id = ?
            AND u.tipo_usuario = 'conductor'
        ");
        $stmt->execute([$empresaId]);
        $calificaciones = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 5. Viajes del mes actual
        $stmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT s.id) as viajes_mes,
                COALESCE(SUM(COALESCE(s.precio_final, s.precio_estimado, 0)), 0) as ingresos_mes
            FROM solicitudes_servicio s
            JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = ?
            AND s.estado = 'completada'
            AND s.completado_en >= DATE_TRUNC('month', CURRENT_DATE)
        ");
        $stmt->execute([$empresaId]);
        $viajesMes = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 6. Actualizar o insertar métricas
        $stmt = $db->prepare("
            INSERT INTO empresas_metricas (
                empresa_id,
                total_conductores,
                conductores_activos,
                conductores_pendientes,
                total_viajes_completados,
                total_viajes_cancelados,
                calificacion_promedio,
                total_calificaciones,
                ingresos_totales,
                viajes_mes,
                ingresos_mes,
                ultima_actualizacion
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON CONFLICT (empresa_id) DO UPDATE SET
                total_conductores = EXCLUDED.total_conductores,
                conductores_activos = EXCLUDED.conductores_activos,
                conductores_pendientes = EXCLUDED.conductores_pendientes,
                total_viajes_completados = EXCLUDED.total_viajes_completados,
                total_viajes_cancelados = EXCLUDED.total_viajes_cancelados,
                calificacion_promedio = EXCLUDED.calificacion_promedio,
                total_calificaciones = EXCLUDED.total_calificaciones,
                ingresos_totales = EXCLUDED.ingresos_totales,
                viajes_mes = EXCLUDED.viajes_mes,
                ingresos_mes = EXCLUDED.ingresos_mes,
                ultima_actualizacion = NOW()
        ");
        
        $stmt->execute([
            $empresaId,
            $conductores['total_conductores'] ?? 0,
            $conductores['conductores_activos'] ?? 0,
            $conductores['conductores_pendientes'] ?? 0,
            $viajes['viajes_completados'] ?? 0,
            $cancelados['viajes_cancelados'] ?? 0,
            round($calificaciones['promedio'] ?? 0, 2),
            $calificaciones['total'] ?? 0,
            $viajes['ingresos_totales'] ?? 0,
            $viajesMes['viajes_mes'] ?? 0,
            $viajesMes['ingresos_mes'] ?? 0
        ]);
        
        echo "  - Conductores: {$conductores['total_conductores']} (activos: {$conductores['conductores_activos']})\n";
        echo "  - Viajes completados: {$viajes['viajes_completados']}\n";
        echo "  - Viajes cancelados: {$cancelados['viajes_cancelados']}\n";
        echo "  - Calificación promedio: " . round($calificaciones['promedio'] ?? 0, 2) . " ({$calificaciones['total']} calificaciones)\n";
        echo "  - Ingresos totales: $" . number_format($viajes['ingresos_totales'] ?? 0, 2) . "\n";
        echo "  ✅ Métricas actualizadas\n\n";
    }
    
    echo "=== SINCRONIZACIÓN COMPLETADA ===\n";
    
    // Mostrar resultados finales
    echo "\n=== MÉTRICAS ACTUALIZADAS ===\n";
    $stmt = $db->query("
        SELECT 
            e.nombre,
            em.total_conductores,
            em.total_viajes_completados,
            em.calificacion_promedio,
            em.total_calificaciones
        FROM empresas_metricas em
        JOIN empresas_transporte e ON em.empresa_id = e.id
        ORDER BY e.nombre
    ");
    
    foreach ($stmt as $row) {
        echo sprintf(
            "%s: %d conductores, %d viajes, %.2f★ (%d reseñas)\n",
            $row['nombre'],
            $row['total_conductores'],
            $row['total_viajes_completados'],
            $row['calificacion_promedio'],
            $row['total_calificaciones']
        );
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
