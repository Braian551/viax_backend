<?php
/**
 * Script para ejecutar la migración 007 - Sistema de Precios
 * 
 * Ejecuta la migración de configuración de precios y verifica la instalación
 */

require_once '../config/database.php';

echo "==============================================\n";
echo "  MIGRACIÓN 007: Sistema de Precios\n";
echo "==============================================\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Leer el archivo SQL
    $sql_file = __DIR__ . '/007_create_configuracion_precios.sql';
    
    if (!file_exists($sql_file)) {
        die("❌ Error: No se encuentra el archivo 007_create_configuracion_precios.sql\n");
    }
    
    $sql = file_get_contents($sql_file);
    
    // Dividir en statements individuales
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   strpos($stmt, '--') !== 0 && 
                   strpos($stmt, '/*') !== 0;
        }
    );
    
    echo "📝 Ejecutando migración...\n\n";
    
    $conn->beginTransaction();
    
    foreach ($statements as $index => $statement) {
        if (stripos($statement, 'CREATE TABLE') !== false) {
            // Extraer nombre de tabla
            preg_match('/CREATE TABLE.*?`([^`]+)`/i', $statement, $matches);
            $table = $matches[1] ?? 'desconocida';
            echo "  ⚙️  Creando tabla: $table\n";
        } elseif (stripos($statement, 'INSERT INTO') !== false) {
            preg_match('/INSERT INTO.*?`([^`]+)`/i', $statement, $matches);
            $table = $matches[1] ?? 'desconocida';
            echo "  📥 Insertando datos en: $table\n";
        } elseif (stripos($statement, 'CREATE OR REPLACE VIEW') !== false) {
            preg_match('/VIEW\s+`([^`]+)`/i', $statement, $matches);
            $view = $matches[1] ?? 'desconocida';
            echo "  👁️  Creando vista: $view\n";
        }
        
        try {
            $conn->exec($statement);
        } catch (PDOException $e) {
            // Ignorar errores de "tabla ya existe"
            if (strpos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }
    
    $conn->commit();
    
    echo "\n✅ Migración ejecutada exitosamente!\n\n";
    
    // Verificar instalación
    echo "==============================================\n";
    echo "  VERIFICACIÓN\n";
    echo "==============================================\n\n";
    
    // Verificar tablas
    $tables = ['configuracion_precios', 'historial_precios'];
    foreach ($tables as $table) {
        $stmt = $conn->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "  ✓ Tabla '$table' creada\n";
        } else {
            echo "  ✗ Tabla '$table' NO encontrada\n";
        }
    }
    
    // Verificar vista
    $stmt = $conn->query("SHOW FULL TABLES WHERE table_type = 'VIEW' AND Tables_in_pingo LIKE 'vista_precios_activos'");
    if ($stmt->rowCount() > 0) {
        echo "  ✓ Vista 'vista_precios_activos' creada\n";
    } else {
        echo "  ✗ Vista 'vista_precios_activos' NO encontrada\n";
    }
    
    // Contar configuraciones
    $stmt = $conn->query("SELECT COUNT(*) as total FROM configuracion_precios WHERE activo = 1");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "  ✓ $count configuraciones de precios activas\n\n";
    
    // Mostrar configuraciones
    echo "==============================================\n";
    echo "  CONFIGURACIONES INSTALADAS\n";
    echo "==============================================\n\n";
    
    $stmt = $conn->query("
        SELECT tipo_vehiculo, tarifa_base, costo_por_km, costo_por_minuto, tarifa_minima
        FROM configuracion_precios 
        WHERE activo = 1 
        ORDER BY tipo_vehiculo
    ");
    
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($configs as $config) {
        echo "  🚗 " . strtoupper($config['tipo_vehiculo']) . "\n";
        echo "     Tarifa base: $" . number_format($config['tarifa_base'], 0) . "\n";
        echo "     Por km: $" . number_format($config['costo_por_km'], 0) . "\n";
        echo "     Por minuto: $" . number_format($config['costo_por_minuto'], 0) . "\n";
        echo "     Mínimo: $" . number_format($config['tarifa_minima'], 0) . "\n\n";
    }
    
    echo "==============================================\n";
    echo "  ✅ INSTALACIÓN COMPLETADA\n";
    echo "==============================================\n\n";
    echo "Próximos pasos:\n";
    echo "1. Verificar que los precios sean correctos\n";
    echo "2. Ajustar valores según sea necesario\n";
    echo "3. Probar los endpoints:\n";
    echo "   - GET /pricing/get_config.php\n";
    echo "   - POST /pricing/calculate_quote.php\n\n";
    
} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "\n❌ Error de base de datos:\n";
    echo $e->getMessage() . "\n\n";
    exit(1);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "\n❌ Error:\n";
    echo $e->getMessage() . "\n\n";
    exit(1);
}
?>
