<?php
/**
 * Actualizar constraint para permitir taxi en configuracion_precios
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "Eliminando constraint check_tipo_vehiculo...\n";
    
    // Eliminar constraint existente
    $conn->exec("ALTER TABLE configuracion_precios DROP CONSTRAINT IF EXISTS check_tipo_vehiculo");
    
    echo "Constraint eliminado. Insertando precios para taxi...\n";
    
    // Insertar configuración de precios para taxi
    $stmt = $conn->prepare("
        INSERT INTO configuracion_precios (
            tipo_vehiculo,
            tarifa_base,
            costo_por_km,
            costo_por_minuto,
            tarifa_minima,
            recargo_hora_pico,
            recargo_nocturno,
            recargo_festivo,
            comision_plataforma,
            distancia_minima,
            distancia_maxima,
            activo,
            notas
        ) VALUES (
            'taxi',
            7000.00,
            3200.00,
            450.00,
            10000.00,
            22.00,
            28.00,
            35.00,
            0.00,
            0.5,
            100.00,
            1,
            'Configuración global para taxi - Enero 2026'
        )
    ");
    $stmt->execute();
    
    echo "\n✅ Configuración de taxi insertada exitosamente.\n";
    
    // Verificar
    echo "\nConfiguraciones de precios activas:\n";
    $stmt = $conn->query("SELECT tipo_vehiculo, tarifa_base, costo_por_km, tarifa_minima FROM configuracion_precios WHERE activo = 1 ORDER BY tipo_vehiculo");
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($configs as $config) {
        echo "  - {$config['tipo_vehiculo']}: Base \${$config['tarifa_base']}, Por km \${$config['costo_por_km']}, Mínimo \${$config['tarifa_minima']}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
