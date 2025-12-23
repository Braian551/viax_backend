<?php
/**
 * Script para ejecutar la migración 012 - Actualización de Tipos de Vehículos
 * 
 * Este script actualiza los tipos de vehículos a:
 * - auto (antes carro)
 * - moto
 * - motocarro (antes moto_carga)
 * 
 * Ejecutar: php run_migration_012.php
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Migración 012: Actualización de Tipos de Vehículos ===\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "✓ Conexión a PostgreSQL establecida\n\n";
    
    // Leer y ejecutar el archivo SQL
    $sqlFile = __DIR__ . '/012_update_vehicle_types.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("No se encontro el archivo de migracion: " . $sqlFile);
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Dividir las sentencias SQL por punto y coma (pero cuidando los bloques DO)
    // Para PostgreSQL con bloques DO, es mejor ejecutar todo de una vez
    
    echo "Ejecutando migración...\n\n";
    
    // Ejecutar paso a paso para mejor control
    $conn->beginTransaction();
    
    try {
        // 1. Actualizar valores en configuracion_precios
        echo "1. Actualizando tipo 'carro' a 'auto'...\n";
        $stmt = $conn->prepare("UPDATE configuracion_precios SET tipo_vehiculo = 'auto' WHERE tipo_vehiculo = 'carro'");
        $stmt->execute();
        echo "   - Registros actualizados: " . $stmt->rowCount() . "\n";
        
        echo "2. Actualizando tipo 'carro_carga' a 'auto'...\n";
        $stmt = $conn->prepare("UPDATE configuracion_precios SET tipo_vehiculo = 'auto' WHERE tipo_vehiculo = 'carro_carga'");
        $stmt->execute();
        echo "   - Registros actualizados: " . $stmt->rowCount() . "\n";
        
        echo "3. Actualizando tipo 'moto_carga' a 'motocarro'...\n";
        $stmt = $conn->prepare("UPDATE configuracion_precios SET tipo_vehiculo = 'motocarro' WHERE tipo_vehiculo = 'moto_carga'");
        $stmt->execute();
        echo "   - Registros actualizados: " . $stmt->rowCount() . "\n";
        
        // 2. Verificar si existe configuración para 'auto'
        echo "\n4. Verificando configuración de 'auto'...\n";
        $stmt = $conn->prepare("SELECT id FROM configuracion_precios WHERE tipo_vehiculo = 'auto'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            echo "   - Insertando configuración para 'auto'...\n";
            $stmt = $conn->prepare("
                INSERT INTO configuracion_precios (
                    tipo_vehiculo, tarifa_base, costo_por_km, costo_por_minuto,
                    tarifa_minima, recargo_hora_pico, recargo_nocturno,
                    recargo_festivo, comision_plataforma, activo, notas
                ) VALUES (
                    'auto', 6000.00, 3000.00, 400.00,
                    9000.00, 20.00, 25.00,
                    30.00, 15.00, 1,
                    'Configuracion para servicio de auto - Diciembre 2025'
                )
            ");
            $stmt->execute();
            echo "   ✓ Configuración de 'auto' insertada\n";
        } else {
            echo "   ✓ Configuración de 'auto' ya existe\n";
        }
        
        // 3. Verificar si existe configuración para 'moto'
        echo "5. Verificando configuración de 'moto'...\n";
        $stmt = $conn->prepare("SELECT id FROM configuracion_precios WHERE tipo_vehiculo = 'moto'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            echo "   - Insertando configuración para 'moto'...\n";
            $stmt = $conn->prepare("
                INSERT INTO configuracion_precios (
                    tipo_vehiculo, tarifa_base, costo_por_km, costo_por_minuto,
                    tarifa_minima, recargo_hora_pico, recargo_nocturno,
                    recargo_festivo, comision_plataforma, activo, notas
                ) VALUES (
                    'moto', 4000.00, 2000.00, 250.00,
                    6000.00, 15.00, 20.00,
                    25.00, 15.00, 1,
                    'Configuracion para servicio de moto - Diciembre 2025'
                )
            ");
            $stmt->execute();
            echo "   ✓ Configuración de 'moto' insertada\n";
        } else {
            echo "   ✓ Configuración de 'moto' ya existe\n";
        }
        
        // 4. Verificar si existe configuración para 'motocarro'
        echo "6. Verificando configuración de 'motocarro'...\n";
        $stmt = $conn->prepare("SELECT id FROM configuracion_precios WHERE tipo_vehiculo = 'motocarro'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            echo "   - Insertando configuración para 'motocarro'...\n";
            $stmt = $conn->prepare("
                INSERT INTO configuracion_precios (
                    tipo_vehiculo, tarifa_base, costo_por_km, costo_por_minuto,
                    tarifa_minima, recargo_hora_pico, recargo_nocturno,
                    recargo_festivo, comision_plataforma, activo, notas
                ) VALUES (
                    'motocarro', 5500.00, 2500.00, 350.00,
                    8000.00, 18.00, 22.00,
                    28.00, 15.00, 1,
                    'Configuracion para servicio de motocarro - Diciembre 2025'
                )
            ");
            $stmt->execute();
            echo "   ✓ Configuración de 'motocarro' insertada\n";
        } else {
            echo "   ✓ Configuración de 'motocarro' ya existe\n";
        }
        
        // 5. Eliminar tipos antiguos que no se usan
        echo "\n7. Eliminando configuraciones de tipos antiguos...\n";
        $stmt = $conn->prepare("DELETE FROM configuracion_precios WHERE tipo_vehiculo NOT IN ('auto', 'moto', 'motocarro')");
        $stmt->execute();
        echo "   - Registros eliminados: " . $stmt->rowCount() . "\n";
        
        // 6. Actualizar solicitudes_servicio si existe
        echo "\n8. Verificando tabla solicitudes_servicio...\n";
        $stmt = $conn->prepare("
            SELECT column_name FROM information_schema.columns 
            WHERE table_name = 'solicitudes_servicio' AND column_name = 'tipo_vehiculo'
        ");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            echo "   - Actualizando tipos en solicitudes_servicio...\n";
            $conn->exec("UPDATE solicitudes_servicio SET tipo_vehiculo = 'auto' WHERE tipo_vehiculo = 'carro'");
            $conn->exec("UPDATE solicitudes_servicio SET tipo_vehiculo = 'auto' WHERE tipo_vehiculo = 'carro_carga'");
            $conn->exec("UPDATE solicitudes_servicio SET tipo_vehiculo = 'motocarro' WHERE tipo_vehiculo = 'moto_carga'");
            echo "   ✓ Tipos actualizados en solicitudes_servicio\n";
        } else {
            echo "   - Columna tipo_vehiculo no existe en solicitudes_servicio\n";
        }
        
        // 7. Actualizar detalles_conductor si existe
        echo "\n9. Verificando tabla detalles_conductor...\n";
        $stmt = $conn->prepare("
            SELECT column_name FROM information_schema.columns 
            WHERE table_name = 'detalles_conductor' AND column_name = 'tipo_vehiculo'
        ");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            echo "   - Actualizando tipos en detalles_conductor...\n";
            $conn->exec("UPDATE detalles_conductor SET tipo_vehiculo = 'auto' WHERE tipo_vehiculo = 'carro'");
            $conn->exec("UPDATE detalles_conductor SET tipo_vehiculo = 'auto' WHERE tipo_vehiculo = 'carro_carga'");
            $conn->exec("UPDATE detalles_conductor SET tipo_vehiculo = 'motocarro' WHERE tipo_vehiculo = 'moto_carga'");
            echo "   ✓ Tipos actualizados en detalles_conductor\n";
        } else {
            echo "   - Columna tipo_vehiculo no existe en detalles_conductor\n";
        }
        
        $conn->commit();
        echo "\n✓ Migración completada exitosamente\n\n";
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
    
    // Mostrar resultado final
    echo "=== Configuraciones de Precios Actuales ===\n\n";
    $stmt = $conn->query("
        SELECT id, tipo_vehiculo, tarifa_base, costo_por_km, tarifa_minima, activo 
        FROM configuracion_precios 
        ORDER BY tipo_vehiculo
    ");
    $configs = $stmt->fetchAll();
    
    printf("%-5s %-12s %-15s %-15s %-15s %-8s\n", 
        "ID", "Tipo", "Tarifa Base", "Costo/km", "Tarifa Min", "Activo");
    echo str_repeat("-", 75) . "\n";
    
    foreach ($configs as $config) {
        printf("%-5s %-12s $%-14s $%-14s $%-14s %-8s\n",
            $config['id'],
            $config['tipo_vehiculo'],
            number_format($config['tarifa_base'], 2),
            number_format($config['costo_por_km'], 2),
            number_format($config['tarifa_minima'], 2),
            $config['activo'] ? 'Sí' : 'No'
        );
    }
    
    echo "\n✓ Total de configuraciones: " . count($configs) . "\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Migración 012 completada ===\n";
?>
