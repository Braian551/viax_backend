<?php
/**
 * Ejecutar migración 022 - Agregar soporte para Google Auth
 */
require_once __DIR__ . '/../config/database.php';

echo "===========================================\n";
echo "Migración 022: Agregar soporte Google Auth\n";
echo "===========================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "✓ Conexión a base de datos establecida\n\n";
    
    // Leer y ejecutar el SQL
    $sqlFile = __DIR__ . '/022_add_google_auth.sql';
    $sql = file_get_contents($sqlFile);
    
    echo "Ejecutando migración...\n\n";
    
    // Ejecutar cada statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && stripos($stmt, '--') !== 0;
        }
    );
    
    // Para PostgreSQL con bloques DO, necesitamos ejecutar todo junto
    $db->exec($sql);
    
    echo "✓ Migración ejecutada exitosamente\n\n";
    
    // Verificar que las columnas se crearon
    echo "Verificando estructura creada...\n\n";
    
    $checkColumns = $db->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_name = 'usuarios'
        AND column_name IN ('google_id', 'apple_id', 'auth_provider')
        ORDER BY column_name
    ");
    $columns = $checkColumns->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($columns) > 0) {
        echo "✓ Columnas creadas:\n";
        foreach ($columns as $col) {
            echo "  - {$col['column_name']} ({$col['data_type']})\n";
        }
        echo "\n";
    } else {
        echo "⚠ No se encontraron las nuevas columnas\n\n";
    }
    
    // Verificar índices
    $checkIndexes = $db->query("
        SELECT indexname 
        FROM pg_indexes 
        WHERE tablename = 'usuarios' 
        AND indexname LIKE '%google%' OR indexname LIKE '%apple%'
    ");
    $indexes = $checkIndexes->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($indexes) > 0) {
        echo "✓ Índices creados:\n";
        foreach ($indexes as $idx) {
            echo "  - {$idx['indexname']}\n";
        }
        echo "\n";
    }
    
    echo "===========================================\n";
    echo "Migración completada exitosamente!\n";
    echo "===========================================\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
