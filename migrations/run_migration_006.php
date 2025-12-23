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
    
    // Crear directorio de uploads si no existe
    $uploadsDir = __DIR__ . '/../uploads';
    $documentosDir = $uploadsDir . '/documentos';
    
    if (!file_exists($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
        echo "\n✓ Directorio uploads creado\n";
    }
    
    if (!file_exists($documentosDir)) {
        mkdir($documentosDir, 0755, true);
        echo "✓ Directorio documentos creado\n";
    }
    
    // Crear .htaccess para protección
    $htaccess = $uploadsDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "# Protección de archivos\n<Files *.php>\n    Deny from all\n</Files>\n");
        echo "✓ Archivo .htaccess creado\n";
    }
    
    // Crear .gitignore
    $gitignore = $uploadsDir . '/.gitignore';
    if (!file_exists($gitignore)) {
        file_put_contents($gitignore, "*\n!.gitignore\n!.htaccess\n");
        echo "✓ Archivo .gitignore creado\n";
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "\n❌ Error en la migración: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
