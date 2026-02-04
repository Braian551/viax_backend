<?php
/**
 * Script para verificar columnas tipo_archivo
 */
require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "=== Verificando columnas tipo_archivo ===\n\n";
    
    // Verificar en detalles_conductor
    $stmt = $db->query("SELECT column_name, data_type, column_default 
                        FROM information_schema.columns 
                        WHERE table_name = 'detalles_conductor' 
                        AND column_name LIKE '%tipo_archivo%'
                        ORDER BY column_name");
    
    echo "Tabla: detalles_conductor\n";
    echo str_repeat("-", 50) . "\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  - {$row['column_name']} ({$row['data_type']}) default: {$row['column_default']}\n";
    }
    
    echo "\n";
    
    // Verificar en documentos_conductor_historial
    $stmt = $db->query("SELECT column_name, data_type, column_default 
                        FROM information_schema.columns 
                        WHERE table_name = 'documentos_conductor_historial' 
                        AND column_name IN ('tipo_archivo', 'nombre_archivo_original', 'tamanio_archivo')
                        ORDER BY column_name");
    
    echo "Tabla: documentos_conductor_historial\n";
    echo str_repeat("-", 50) . "\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  - {$row['column_name']} ({$row['data_type']}) default: {$row['column_default']}\n";
    }
    
    echo "\n✓ Verificación completada.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
