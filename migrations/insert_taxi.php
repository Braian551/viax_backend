<?php
/**
 * Script para insertar Taxi directamente en la BD
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "Insertando Taxi en catalogo_tipos_vehiculo...\n";
    
    // Insertar Taxi
    $stmt = $conn->prepare("
        INSERT INTO catalogo_tipos_vehiculo (codigo, nombre, descripcion, icono, orden, activo) 
        VALUES ('taxi', 'Taxi', 'Taxis tradicionales amarillos', 'local_taxi', 4, true)
        ON CONFLICT (codigo) DO UPDATE SET 
            nombre = EXCLUDED.nombre,
            descripcion = EXCLUDED.descripcion,
            icono = EXCLUDED.icono,
            orden = EXCLUDED.orden,
            activo = EXCLUDED.activo
    ");
    $stmt->execute();
    
    echo "Taxi insertado/actualizado exitosamente.\n\n";
    
    // Verificar
    echo "Tipos de vehiculo en el catalogo:\n";
    $stmt = $conn->query("SELECT codigo, nombre, orden, activo FROM catalogo_tipos_vehiculo ORDER BY orden");
    $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($tipos as $tipo) {
        $estado = $tipo['activo'] ? '✓' : '✗';
        echo "  [{$tipo['orden']}] {$estado} {$tipo['codigo']}: {$tipo['nombre']}\n";
    }
    
    // Insertar configuración de precios para Taxi si no existe
    echo "\nVerificando configuración de precios para taxi...\n";
    
    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM configuracion_precios WHERE tipo_vehiculo = 'taxi' AND empresa_id IS NULL");
    $checkStmt->execute();
    $exists = $checkStmt->fetchColumn() > 0;
    
    if (!$exists) {
        echo "Insertando configuración de precios para taxi...\n";
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
        echo "Configuración de precios insertada.\n";
    } else {
        echo "La configuración de precios para taxi ya existe.\n";
    }
    
    echo "\n✅ Completado\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
