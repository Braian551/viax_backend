<?php
/**
 * Script para ejecutar la migración 006
 * Agrega columnas para fotos de documentos en detalles_conductor
 */

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "=== Iniciando migración 006: Documentos Conductor ===\n\n";
    
    // Leer el archivo SQL
    $sql = file_get_contents(__DIR__ . '/006_add_documentos_conductor.sql');
    
    // Separar las declaraciones SQL
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   !preg_match('/^--/', $stmt) && 
                   !preg_match('/^\/\*/', $stmt);
        }
    );
    
    $db->beginTransaction();
    
    foreach ($statements as $index => $statement) {
        if (empty(trim($statement))) continue;
        
        echo "Ejecutando declaración " . ($index + 1) . "...\n";
        
        try {
            $db->exec($statement);
            echo "✓ Completada\n\n";
        } catch (PDOException $e) {
            // Si el error es que la columna ya existe, continuar
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "⚠ Columna ya existe, continuando...\n\n";
                continue;
            }
            throw $e;
        }
    }
    
    $db->commit();
    
    echo "\n=== Migración 006 completada exitosamente ===\n";
    echo "\nColumnas agregadas a detalles_conductor:\n";
    echo "  - licencia_foto_url\n";
    echo "  - soat_foto_url\n";
    echo "  - tecnomecanica_foto_url\n";
    echo "  - tarjeta_propiedad_foto_url\n";
    echo "  - seguro_foto_url\n";
    echo "\nTabla creada:\n";
    echo "  - documentos_conductor_historial\n";
    
    // NOTA: Ya no se crean directorios locales de uploads
    // Todos los archivos se guardan en Cloudflare R2
    echo "\n✓ Todos los documentos se almacenan en Cloudflare R2\n";
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "\n❌ Error en la migración: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
