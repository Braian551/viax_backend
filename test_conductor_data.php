<?php
/**
 * Script de prueba para verificar datos del conductor
 */
require_once __DIR__ . '/config/database.php';

$conductorId = $argv[1] ?? 586;

echo "=== Verificando datos del conductor $conductorId ===\n\n";

try {
    $db = (new Database())->getConnection();
    
    // Datos de detalles_conductor
    echo "📊 Datos de detalles_conductor:\n";
    $stmt = $db->prepare("
        SELECT 
            dc.total_viajes,
            dc.calificacion_promedio,
            dc.total_calificaciones,
            dc.disponible,
            dc.estado_verificacion,
            dc.creado_en,
            dc.ganancias_totales
        FROM detalles_conductor dc 
        WHERE dc.usuario_id = ?
    ");
    $stmt->execute([$conductorId]);
    $detalles = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($detalles) {
        print_r($detalles);
    } else {
        echo "   ⚠️ No se encontró registro en detalles_conductor\n";
    }
    
    // Datos de usuarios
    echo "\n📋 Datos de usuarios:\n";
    $stmt = $db->prepare("
        SELECT 
            id,
            nombre,
            apellido,
            fecha_registro,
            tipo_usuario
        FROM usuarios 
        WHERE id = ?
    ");
    $stmt->execute([$conductorId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($usuario) {
        print_r($usuario);
    } else {
        echo "   ⚠️ No se encontró usuario\n";
    }
    
    // Viajes reales desde solicitudes_servicio
    echo "\n🚗 Viajes reales desde solicitudes_servicio:\n";
    
    // Viajes donde el conductor fue asignado
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_viajes,
            COUNT(CASE WHEN estado = 'completada' THEN 1 END) as viajes_completados,
            COUNT(CASE WHEN estado = 'en_progreso' THEN 1 END) as viajes_en_progreso,
            COUNT(CASE WHEN estado = 'cancelada' THEN 1 END) as viajes_cancelados
        FROM solicitudes_servicio
        WHERE conductor_id = ?
    ");
    $stmt->execute([$conductorId]);
    $viajesSolicitudes = $stmt->fetch(PDO::FETCH_ASSOC);
    print_r($viajesSolicitudes);
    
    // Viajes desde asignaciones_conductor
    echo "\n🔗 Viajes desde asignaciones_conductor:\n";
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_asignaciones,
            COUNT(CASE WHEN estado = 'completado' THEN 1 END) as asignaciones_completadas,
            COUNT(CASE WHEN estado = 'en_progreso' THEN 1 END) as asignaciones_en_progreso
        FROM asignaciones_conductor
        WHERE conductor_id = ?
    ");
    $stmt->execute([$conductorId]);
    $asignaciones = $stmt->fetch(PDO::FETCH_ASSOC);
    print_r($asignaciones);
    
    // Calificaciones recibidas
    echo "\n⭐ Calificaciones recibidas:\n";
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_calificaciones,
            COALESCE(AVG(calificacion), 0) as promedio
        FROM calificaciones
        WHERE usuario_calificado_id = ?
    ");
    $stmt->execute([$conductorId]);
    $calificaciones = $stmt->fetch(PDO::FETCH_ASSOC);
    print_r($calificaciones);
    
    echo "\n=== Fin de verificación ===\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
