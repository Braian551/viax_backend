<?php
/**
 * run_migration_027.php
 * Ejecuta la migración del sistema de notificaciones
 */

require_once __DIR__ . '/../config/database.php';

echo "==============================================\n";
echo "MIGRACIÓN 027: Sistema de Notificaciones\n";
echo "==============================================\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "✓ Conexión a base de datos establecida\n\n";
    
    // Leer el archivo SQL
    $sqlFile = __DIR__ . '/027_create_notifications_system.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo de migración no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    echo "Ejecutando migración...\n\n";
    
    // Ejecutar la migración
    $conn->exec($sql);
    
    echo "✓ Tablas creadas exitosamente\n";
    echo "✓ Índices creados exitosamente\n";
    echo "✓ Funciones creadas exitosamente\n";
    echo "✓ Triggers creados exitosamente\n";
    echo "✓ Vista creada exitosamente\n\n";
    
    // Verificar las tablas creadas
    echo "Verificando estructura...\n\n";
    
    $tables = [
        'tipos_notificacion',
        'notificaciones_usuario',
        'configuracion_notificaciones_usuario',
        'tokens_push_usuario'
    ];
    
    foreach ($tables as $table) {
        $stmt = $conn->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$table'");
        $exists = $stmt->fetchColumn() > 0;
        echo ($exists ? "✓" : "✗") . " Tabla '$table': " . ($exists ? "OK" : "NO ENCONTRADA") . "\n";
    }
    
    // Verificar tipos de notificación insertados
    echo "\nTipos de notificación insertados:\n";
    $stmt = $conn->query("SELECT codigo, nombre FROM tipos_notificacion ORDER BY id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  • {$row['codigo']}: {$row['nombre']}\n";
    }
    
    // Verificar vista
    $stmt = $conn->query("SELECT COUNT(*) FROM information_schema.views WHERE table_name = 'notificaciones_completas'");
    $viewExists = $stmt->fetchColumn() > 0;
    echo "\n" . ($viewExists ? "✓" : "✗") . " Vista 'notificaciones_completas': " . ($viewExists ? "OK" : "NO ENCONTRADA") . "\n";
    
    // Verificar funciones
    $functions = ['crear_notificacion', 'contar_notificaciones_no_leidas'];
    echo "\nFunciones:\n";
    foreach ($functions as $func) {
        $stmt = $conn->query("SELECT COUNT(*) FROM information_schema.routines WHERE routine_name = '$func'");
        $exists = $stmt->fetchColumn() > 0;
        echo ($exists ? "✓" : "✗") . " Función '$func': " . ($exists ? "OK" : "NO ENCONTRADA") . "\n";
    }
    
    echo "\n==============================================\n";
    echo "✓ MIGRACIÓN 027 COMPLETADA EXITOSAMENTE\n";
    echo "==============================================\n";
    
} catch (PDOException $e) {
    echo "\n✗ Error de base de datos: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
