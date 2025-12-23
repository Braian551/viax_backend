<?php
/**
 * Script para eliminar tipos de vehículos no utilizados
 * Solo mantiene configuración para 'moto'
 */

require_once __DIR__ . '/../config/database.php';

echo "========================================\n";
echo "LIMPIEZA DE TIPOS DE VEHÍCULOS\n";
echo "========================================\n\n";

try {
    $database = new Database();
    $pdo = $database->getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✓ Conexión establecida con la base de datos\n\n";
    
    // Paso 1: Verificar configuraciones actuales
    echo "Paso 1: Verificando configuraciones actuales...\n";
    $stmt = $pdo->query("SELECT id, tipo_vehiculo, activo FROM configuracion_precios ORDER BY tipo_vehiculo");
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Configuraciones encontradas:\n";
    foreach ($configs as $config) {
        $status = $config['activo'] ? 'ACTIVO' : 'INACTIVO';
        echo "  - ID: {$config['id']}, Tipo: {$config['tipo_vehiculo']}, Estado: $status\n";
    }
    echo "\n";
    
    // Paso 2: Eliminar configuraciones de tipos no deseados
    echo "Paso 2: Eliminando tipos de vehículos no utilizados...\n";
    $vehiculos_eliminar = ['carro', 'moto_carga', 'carro_carga'];
    
    foreach ($vehiculos_eliminar as $tipo) {
        $stmt = $pdo->prepare("DELETE FROM configuracion_precios WHERE tipo_vehiculo = ?");
        $stmt->execute([$tipo]);
        $affected = $stmt->rowCount();
        
        if ($affected > 0) {
            echo "  ✓ Eliminadas $affected configuración(es) de tipo '$tipo'\n";
        } else {
            echo "  - No se encontraron configuraciones de tipo '$tipo'\n";
        }
    }
    echo "\n";
    
    // Paso 3: Verificar que solo quede 'moto'
    echo "Paso 3: Verificando configuración final...\n";
    $stmt = $pdo->query("SELECT id, tipo_vehiculo, activo FROM configuracion_precios");
    $remaining = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($remaining) === 0) {
        echo "  ⚠ ADVERTENCIA: No quedaron configuraciones. Creando configuración para moto...\n";
        
        $sql = "INSERT INTO configuracion_precios (
            tipo_vehiculo, tarifa_base, costo_por_km, costo_por_minuto, 
            tarifa_minima, recargo_hora_pico, recargo_nocturno, recargo_festivo,
            comision_plataforma, activo, notas
        ) VALUES (
            'moto', 4000.00, 2000.00, 250.00, 6000.00, 
            15.00, 20.00, 25.00, 15.00, 1,
            'Configuración por defecto para servicio de moto'
        )";
        
        $pdo->exec($sql);
        echo "  ✓ Configuración de moto creada exitosamente\n";
        
        // Volver a consultar
        $stmt = $pdo->query("SELECT id, tipo_vehiculo, activo FROM configuracion_precios");
        $remaining = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo "\nConfiguración(es) final(es):\n";
    foreach ($remaining as $config) {
        $status = $config['activo'] ? 'ACTIVO' : 'INACTIVO';
        echo "  - ID: {$config['id']}, Tipo: {$config['tipo_vehiculo']}, Estado: $status\n";
    }
    
    // Paso 4: Actualizar el ENUM de tipo_vehiculo (opcional)
    echo "\n";
    echo "Paso 4: Modificando restricción de tipos de vehículos...\n";
    
    try {
        $sql = "ALTER TABLE configuracion_precios 
                MODIFY COLUMN tipo_vehiculo ENUM('moto') NOT NULL DEFAULT 'moto'";
        $pdo->exec($sql);
        echo "  ✓ Columna tipo_vehiculo actualizada para aceptar solo 'moto'\n";
    } catch (PDOException $e) {
        echo "  ⚠ No se pudo modificar el ENUM (puede que ya esté actualizado): " . $e->getMessage() . "\n";
    }
    
    echo "\n========================================\n";
    echo "✓ LIMPIEZA COMPLETADA EXITOSAMENTE\n";
    echo "========================================\n";
    echo "\nResumen:\n";
    echo "  - Solo se mantiene configuración para tipo 'moto'\n";
    echo "  - Tipos eliminados: carro, moto_carga, carro_carga\n";
    echo "  - Total de configuraciones activas: " . count($remaining) . "\n";
    
} catch (PDOException $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Código de error: " . $e->getCode() . "\n";
    exit(1);
}
