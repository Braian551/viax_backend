<?php
/**
 * Ejecutar migración 033: Agregar tipo_vehiculo y empresa_id a solicitudes_servicio
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Ejecutando migración 033: tipo_vehiculo y empresa_id en solicitudes_servicio ===\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Leer y ejecutar el archivo SQL
    $sqlFile = __DIR__ . '/033_add_vehiculo_empresa_to_solicitudes.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    echo "Ejecutando SQL...\n";
    $db->exec($sql);
    
    echo "\n✅ Migración ejecutada correctamente\n\n";
    
    // Verificar las columnas
    echo "Verificando estructura de solicitudes_servicio:\n";
    $stmt = $db->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio'
        AND column_name IN ('tipo_vehiculo', 'empresa_id', 'precio_estimado', 'metodo_pago', 'conductor_id')
        ORDER BY ordinal_position
    ");
    
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($columns)) {
        echo "⚠️  No se encontraron las columnas nuevas\n";
    } else {
        foreach ($columns as $col) {
            echo "  ✓ {$col['column_name']}: {$col['data_type']}";
            echo " (nullable: {$col['is_nullable']})";
            if ($col['column_default']) {
                echo " default: {$col['column_default']}";
            }
            echo "\n";
        }
    }
    
    // Verificar índices
    echo "\nVerificando índices:\n";
    $stmt = $db->query("
        SELECT indexname, indexdef
        FROM pg_indexes
        WHERE tablename = 'solicitudes_servicio'
        AND indexname LIKE 'idx_solicitudes%'
    ");
    
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($indexes as $idx) {
        echo "  ✓ {$idx['indexname']}\n";
    }
    
    echo "\n✅ Migración 033 completada exitosamente\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
