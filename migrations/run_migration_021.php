<?php
/**
 * Ejecutar migración 021: Soporte para PDF y mejoras en documentos
 * 
 * Este script agrega campos para distinguir entre imágenes y PDFs
 * en los documentos del conductor.
 */

require_once __DIR__ . '/../config/database.php';

echo "==============================================\n";
echo "  MIGRACIÓN 021: Soporte PDF y tipos de archivo\n";
echo "==============================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "✓ Conexión a base de datos establecida\n\n";
    
    // Ejecutar las alteraciones una por una para mejor control
    $alteraciones = [
        // Campos tipo_archivo para detalles_conductor
        [
            'descripcion' => 'Agregar licencia_tipo_archivo',
            'sql' => "ALTER TABLE detalles_conductor ADD COLUMN IF NOT EXISTS licencia_tipo_archivo VARCHAR(10) DEFAULT 'imagen'"
        ],
        [
            'descripcion' => 'Agregar soat_tipo_archivo',
            'sql' => "ALTER TABLE detalles_conductor ADD COLUMN IF NOT EXISTS soat_tipo_archivo VARCHAR(10) DEFAULT 'imagen'"
        ],
        [
            'descripcion' => 'Agregar tecnomecanica_tipo_archivo',
            'sql' => "ALTER TABLE detalles_conductor ADD COLUMN IF NOT EXISTS tecnomecanica_tipo_archivo VARCHAR(10) DEFAULT 'imagen'"
        ],
        [
            'descripcion' => 'Agregar tarjeta_propiedad_tipo_archivo',
            'sql' => "ALTER TABLE detalles_conductor ADD COLUMN IF NOT EXISTS tarjeta_propiedad_tipo_archivo VARCHAR(10) DEFAULT 'imagen'"
        ],
        [
            'descripcion' => 'Agregar seguro_tipo_archivo',
            'sql' => "ALTER TABLE detalles_conductor ADD COLUMN IF NOT EXISTS seguro_tipo_archivo VARCHAR(10) DEFAULT 'imagen'"
        ],
        // Campos adicionales para historial
        [
            'descripcion' => 'Agregar tipo_archivo a historial',
            'sql' => "ALTER TABLE documentos_conductor_historial ADD COLUMN IF NOT EXISTS tipo_archivo VARCHAR(10) DEFAULT 'imagen'"
        ],
        [
            'descripcion' => 'Agregar nombre_archivo_original a historial',
            'sql' => "ALTER TABLE documentos_conductor_historial ADD COLUMN IF NOT EXISTS nombre_archivo_original VARCHAR(255)"
        ],
        [
            'descripcion' => 'Agregar tamanio_archivo a historial',
            'sql' => "ALTER TABLE documentos_conductor_historial ADD COLUMN IF NOT EXISTS tamanio_archivo INTEGER"
        ],
    ];
    
    $exitosos = 0;
    $fallidos = 0;
    
    foreach ($alteraciones as $alt) {
        try {
            $db->exec($alt['sql']);
            echo "  ✓ {$alt['descripcion']}\n";
            $exitosos++;
        } catch (PDOException $e) {
            // Si ya existe, no es error crítico
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'duplicate column') !== false) {
                echo "  ℹ {$alt['descripcion']} (ya existe)\n";
                $exitosos++;
            } else {
                echo "  ✗ {$alt['descripcion']}: {$e->getMessage()}\n";
                $fallidos++;
            }
        }
    }
    
    echo "\n";
    
    // Crear índice si no existe
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_documentos_historial_tipo ON documentos_conductor_historial(tipo_documento, tipo_archivo)");
        echo "✓ Índice idx_documentos_historial_tipo creado/verificado\n";
    } catch (PDOException $e) {
        echo "ℹ Índice: {$e->getMessage()}\n";
    }
    
    echo "\n==============================================\n";
    echo "  RESUMEN\n";
    echo "==============================================\n";
    echo "  Operaciones exitosas: $exitosos\n";
    echo "  Operaciones fallidas: $fallidos\n";
    
    // Verificar estructura final
    echo "\n=== Verificando columnas nuevas ===\n";
    $stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'detalles_conductor' AND column_name LIKE '%tipo_archivo%'");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($cols) > 0) {
        echo "✓ Columnas tipo_archivo encontradas:\n";
        foreach ($cols as $col) {
            echo "  - $col\n";
        }
    }
    
    echo "\n✓ Migración 021 completada.\n";
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
