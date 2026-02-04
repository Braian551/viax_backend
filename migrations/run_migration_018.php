<?php
/**
 * Script para ejecutar la migración 018: Sistema de Empresas de Transporte
 * 
 * Uso: php run_migration_018.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

echo "======================================================\n";
echo "Migración 018: Sistema de Empresas de Transporte\n";
echo "======================================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "✓ Conexión a la base de datos establecida\n\n";
    
    // Leer el archivo SQL
    $sqlFile = __DIR__ . '/018_create_empresas_transporte.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo de migración no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Ejecutar la migración
    echo "Ejecutando migración...\n\n";
    
    $db->exec($sql);
    
    echo "✓ Migración ejecutada exitosamente\n\n";
    
    // Verificar que la tabla se creó correctamente
    echo "Verificando estructura creada...\n\n";
    
    // Verificar tabla empresas_transporte
    $checkTable = $db->query("SELECT column_name, data_type 
                              FROM information_schema.columns 
                              WHERE table_name = 'empresas_transporte' 
                              ORDER BY ordinal_position");
    $columns = $checkTable->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($columns) > 0) {
        echo "✓ Tabla 'empresas_transporte' creada con " . count($columns) . " columnas:\n";
        foreach ($columns as $col) {
            echo "  - {$col['column_name']} ({$col['data_type']})\n";
        }
        echo "\n";
    } else {
        echo "⚠ La tabla 'empresas_transporte' no se encontró\n\n";
    }
    
    // Verificar columnas en usuarios
    $checkUsuarios = $db->query("SELECT column_name, data_type 
                                 FROM information_schema.columns 
                                 WHERE table_name = 'usuarios' 
                                 AND column_name IN ('empresa_id', 'empresa_preferida_id')");
    $usuariosCols = $checkUsuarios->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($usuariosCols) > 0) {
        echo "✓ Columnas agregadas a la tabla 'usuarios':\n";
        foreach ($usuariosCols as $col) {
            echo "  - {$col['column_name']} ({$col['data_type']})\n";
        }
        echo "\n";
    }
    
    // Verificar índices
    $checkIndexes = $db->query("SELECT indexname FROM pg_indexes 
                                WHERE tablename = 'empresas_transporte'");
    $indexes = $checkIndexes->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($indexes) > 0) {
        echo "✓ Índices creados:\n";
        foreach ($indexes as $idx) {
            echo "  - {$idx['indexname']}\n";
        }
        echo "\n";
    }
    
    echo "======================================================\n";
    echo "MIGRACIÓN COMPLETADA EXITOSAMENTE\n";
    echo "======================================================\n";
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
    exit(1);
}
?>
