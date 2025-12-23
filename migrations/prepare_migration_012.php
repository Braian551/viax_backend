<?php
/**
 * Script para eliminar constraint CHECK existente y preparar la migracion 012
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Preparando base de datos para migracion 012 ===\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "Conexion establecida\n\n";
    
    // 1. Buscar y eliminar constraint CHECK existente
    echo "1. Buscando constraints CHECK en configuracion_precios...\n";
    
    $stmt = $conn->query("
        SELECT conname 
        FROM pg_constraint 
        WHERE conrelid = 'configuracion_precios'::regclass 
        AND contype = 'c'
    ");
    
    $constraints = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($constraints) > 0) {
        foreach ($constraints as $constraint) {
            echo "   - Eliminando constraint: $constraint\n";
            // Usar comillas dobles para identificadores en PostgreSQL
            $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $constraint);
            $conn->exec("ALTER TABLE configuracion_precios DROP CONSTRAINT IF EXISTS \"" . $safeName . "\"");
        }
        echo "   Constraints eliminados\n";
    } else {
        echo "   - No hay constraints CHECK existentes\n";
    }
    
    // 2. Buscar constraints en solicitudes_servicio
    echo "\n2. Buscando constraints CHECK en solicitudes_servicio...\n";
    
    $stmt = $conn->query("
        SELECT conname 
        FROM pg_constraint 
        WHERE conrelid = 'solicitudes_servicio'::regclass 
        AND contype = 'c'
    ");
    
    $constraints = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($constraints) > 0) {
        foreach ($constraints as $constraint) {
            echo "   - Eliminando constraint: $constraint\n";
            $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $constraint);
            $conn->exec("ALTER TABLE solicitudes_servicio DROP CONSTRAINT IF EXISTS \"" . $safeName . "\"");
        }
        echo "   Constraints eliminados\n";
    } else {
        echo "   - No hay constraints CHECK existentes\n";
    }
    
    // 3. Buscar constraints en detalles_conductor
    echo "\n3. Buscando constraints CHECK en detalles_conductor...\n";
    
    $stmt = $conn->query("
        SELECT conname 
        FROM pg_constraint 
        WHERE conrelid = 'detalles_conductor'::regclass 
        AND contype = 'c'
    ");
    
    $constraints = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($constraints) > 0) {
        foreach ($constraints as $constraint) {
            echo "   - Eliminando constraint: $constraint\n";
            $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $constraint);
            $conn->exec("ALTER TABLE detalles_conductor DROP CONSTRAINT IF EXISTS \"" . $safeName . "\"");
        }
        echo "   Constraints eliminados\n";
    } else {
        echo "   - No hay constraints CHECK existentes\n";
    }
    
    echo "\nBase de datos preparada. Ahora puede ejecutar run_migration_012.php\n";
    
} catch (Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    exit(1);
}
?>
