<?php
/**
 * Script para consolidar configuraciones de moto duplicadas
 * Deja solo una configuración activa de moto
 */

require_once __DIR__ . '/../config/database.php';

echo "========================================\n";
echo "CONSOLIDACIÓN DE CONFIGURACIONES DE MOTO\n";
echo "========================================\n\n";

try {
    $database = new Database();
    $pdo = $database->getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✓ Conexión establecida con la base de datos\n\n";
    
    // Paso 1: Verificar configuraciones de moto
    echo "Paso 1: Verificando configuraciones de moto...\n";
    $stmt = $pdo->query("SELECT id, tipo_vehiculo, activo, fecha_creacion FROM configuracion_precios WHERE tipo_vehiculo = 'moto' ORDER BY id ASC");
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($configs) === 0) {
        echo "  ⚠ No se encontraron configuraciones de moto\n";
        exit(1);
    }
    
    echo "Configuraciones de moto encontradas: " . count($configs) . "\n";
    foreach ($configs as $config) {
        $status = $config['activo'] ? 'ACTIVO' : 'INACTIVO';
        echo "  - ID: {$config['id']}, Estado: $status, Fecha: {$config['fecha_creacion']}\n";
    }
    echo "\n";
    
    if (count($configs) === 1) {
        echo "✓ Solo hay una configuración de moto. No se requiere consolidación.\n";
        exit(0);
    }
    
    // Paso 2: Mantener solo la configuración más reciente (mayor ID)
    echo "Paso 2: Consolidando configuraciones...\n";
    
    // Ordenar por ID descendente y tomar la primera (más reciente)
    usort($configs, function($a, $b) {
        return $b['id'] - $a['id'];
    });
    
    $keepId = $configs[0]['id'];
    echo "  → Se mantendrá la configuración con ID: $keepId\n";
    
    // Eliminar las demás
    $deleteIds = array_column(array_slice($configs, 1), 'id');
    
    if (!empty($deleteIds)) {
        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM configuracion_precios WHERE id IN ($placeholders)");
        $stmt->execute($deleteIds);
        
        echo "  ✓ Eliminadas " . count($deleteIds) . " configuración(es) duplicada(s)\n";
        echo "    IDs eliminados: " . implode(', ', $deleteIds) . "\n";
    }
    
    // Asegurar que la configuración restante esté activa
    $stmt = $pdo->prepare("UPDATE configuracion_precios SET activo = 1 WHERE id = ?");
    $stmt->execute([$keepId]);
    echo "  ✓ Configuración ID $keepId marcada como activa\n";
    
    echo "\n";
    
    // Paso 3: Verificar resultado final
    echo "Paso 3: Verificando resultado final...\n";
    $stmt = $pdo->query("SELECT id, tipo_vehiculo, activo, tarifa_base, costo_por_km FROM configuracion_precios WHERE tipo_vehiculo = 'moto'");
    $final = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Configuración final:\n";
    foreach ($final as $config) {
        echo "  - ID: {$config['id']}\n";
        echo "    Tipo: {$config['tipo_vehiculo']}\n";
        echo "    Estado: " . ($config['activo'] ? 'ACTIVO' : 'INACTIVO') . "\n";
        echo "    Tarifa Base: \${$config['tarifa_base']}\n";
        echo "    Costo por Km: \${$config['costo_por_km']}\n";
    }
    
    echo "\n========================================\n";
    echo "✓ CONSOLIDACIÓN COMPLETADA EXITOSAMENTE\n";
    echo "========================================\n";
    echo "\nResumen:\n";
    echo "  - Configuraciones de moto consolidadas a: 1\n";
    echo "  - ID de configuración activa: $keepId\n";
    
} catch (PDOException $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Código de error: " . $e->getCode() . "\n";
    exit(1);
}
