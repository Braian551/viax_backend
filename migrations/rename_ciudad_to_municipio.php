<?php
/**
 * Script para renombrar columna ciudad a municipio
 */
require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();
    
    // Verificar si existe la columna 'ciudad' y renombrarla a 'municipio'
    $check = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'empresas_transporte' AND column_name = 'ciudad'");
    
    if ($check->fetch()) {
        $db->exec('ALTER TABLE empresas_transporte RENAME COLUMN ciudad TO municipio');
        echo "✓ Columna 'ciudad' renombrada a 'municipio'\n";
    } else {
        // Verificar si ya existe municipio
        $checkMunicipio = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'empresas_transporte' AND column_name = 'municipio'");
        if ($checkMunicipio->fetch()) {
            echo "✓ La columna 'municipio' ya existe\n";
        } else {
            echo "⚠ No se encontró la columna 'ciudad' ni 'municipio'\n";
        }
    }
    
    // Renombrar índice si existe
    $checkIndex = $db->query("SELECT indexname FROM pg_indexes WHERE indexname = 'idx_empresas_transporte_ciudad'");
    if ($checkIndex->fetch()) {
        $db->exec('ALTER INDEX idx_empresas_transporte_ciudad RENAME TO idx_empresas_transporte_municipio');
        echo "✓ Índice renombrado\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
