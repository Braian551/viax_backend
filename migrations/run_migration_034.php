<?php
/**
 * Migración 034: Sistema de Tracking en Tiempo Real del Viaje
 * 
 * Ejecuta la migración SQL para crear las tablas de tracking.
 * Uso: php run_migration_034.php
 */

require_once '../config/database.php';

echo "============================================================\n";
echo "MIGRACIÓN 034: Sistema de Tracking en Tiempo Real\n";
echo "============================================================\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "✅ Conexión a la base de datos establecida\n\n";
    
    // Leer el archivo SQL de migración
    $sqlFile = __DIR__ . '/034_viaje_tracking_realtime.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo de migración no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    echo "📄 Archivo de migración cargado\n\n";
    
    // Ejecutar la migración en una transacción
    $conn->beginTransaction();
    
    echo "🔄 Ejecutando migración...\n\n";
    
    // Ejecutar todo el SQL
    $conn->exec($sql);
    
    $conn->commit();
    
    echo "✅ Migración ejecutada correctamente\n\n";
    
    // Verificar tablas creadas
    echo "📋 Verificando tablas creadas:\n";
    
    $tables = [
        'viaje_tracking_realtime',
        'viaje_resumen_tracking'
    ];
    
    foreach ($tables as $table) {
        $stmt = $conn->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$table'");
        $exists = $stmt->fetchColumn() > 0;
        echo "   - $table: " . ($exists ? "✅ Existe" : "❌ No existe") . "\n";
    }
    
    // Verificar columnas en solicitudes_servicio
    echo "\n📋 Verificando columnas agregadas a solicitudes_servicio:\n";
    
    $columns = [
        'distancia_recorrida',
        'tiempo_transcurrido',
        'precio_ajustado_por_tracking',
        'tuvo_desvio_ruta'
    ];
    
    foreach ($columns as $column) {
        $stmt = $conn->query("
            SELECT COUNT(*) FROM information_schema.columns 
            WHERE table_name = 'solicitudes_servicio' AND column_name = '$column'
        ");
        $exists = $stmt->fetchColumn() > 0;
        echo "   - $column: " . ($exists ? "✅ Existe" : "❌ No existe") . "\n";
    }
    
    // Verificar función
    echo "\n📋 Verificando funciones:\n";
    $stmt = $conn->query("
        SELECT COUNT(*) FROM pg_proc 
        WHERE proname = 'calcular_precio_por_tracking'
    ");
    $exists = $stmt->fetchColumn() > 0;
    echo "   - calcular_precio_por_tracking: " . ($exists ? "✅ Existe" : "❌ No existe") . "\n";
    
    // Verificar trigger
    echo "\n📋 Verificando triggers:\n";
    $stmt = $conn->query("
        SELECT COUNT(*) FROM pg_trigger 
        WHERE tgname = 'trg_actualizar_resumen_tracking'
    ");
    $exists = $stmt->fetchColumn() > 0;
    echo "   - trg_actualizar_resumen_tracking: " . ($exists ? "✅ Existe" : "❌ No existe") . "\n";
    
    echo "\n============================================================\n";
    echo "✅ MIGRACIÓN 034 COMPLETADA EXITOSAMENTE\n";
    echo "============================================================\n";
    
} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "\n❌ Error de base de datos: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
