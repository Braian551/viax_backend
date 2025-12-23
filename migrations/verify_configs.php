<?php
/**
 * Script de verificación rápida de configuraciones
 */

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $stmt = $pdo->query("SELECT id, tipo_vehiculo, activo, tarifa_base, costo_por_km, tarifa_minima FROM configuracion_precios");
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Configuraciones actuales en la base de datos:\n";
    echo "============================================\n\n";
    
    if (empty($configs)) {
        echo "No se encontraron configuraciones.\n";
    } else {
        foreach ($configs as $config) {
            echo "ID: {$config['id']}\n";
            echo "Tipo de Vehículo: {$config['tipo_vehiculo']}\n";
            echo "Estado: " . ($config['activo'] ? 'ACTIVO' : 'INACTIVO') . "\n";
            echo "Tarifa Base: \${$config['tarifa_base']}\n";
            echo "Costo por Km: \${$config['costo_por_km']}\n";
            echo "Tarifa Mínima: \${$config['tarifa_minima']}\n";
            echo "--------------------------------------------\n";
        }
        echo "\nTotal de configuraciones: " . count($configs) . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
