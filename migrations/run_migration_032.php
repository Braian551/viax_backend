<?php
/**
 * Ejecutar Migración 032: Agregar Taxi al Catálogo
 * 
 * Uso: php run_migration_032.php
 */

require_once '../config/database.php';

echo "==========================================\n";
echo "MIGRACIÓN 032: Agregar Taxi al Catálogo\n";
echo "==========================================\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Leer archivo SQL
    $sqlFile = __DIR__ . '/032_add_taxi_vehicle.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Separar las consultas (ignorar comentarios)
    $queries = array_filter(
        array_map('trim', explode(';', $sql)),
        function($q) {
            $q = trim($q);
            return !empty($q) && !preg_match('/^--/', $q);
        }
    );
    
    $conn->beginTransaction();
    
    foreach ($queries as $index => $query) {
        if (empty(trim($query))) continue;
        
        // Ignorar comentarios
        if (strpos(trim($query), '--') === 0) continue;
        
        echo "Ejecutando query " . ($index + 1) . "...\n";
        
        try {
            $stmt = $conn->prepare($query);
            $stmt->execute();
            
            // Si es SELECT, mostrar resultados
            if (stripos(trim($query), 'SELECT') === 0) {
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo "Resultados:\n";
                print_r($results);
            }
        } catch (PDOException $e) {
            // Ignorar errores de "ya existe"
            if (strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'duplicate key') !== false) {
                echo "  (registro ya existe, continuando...)\n";
            } else {
                throw $e;
            }
        }
    }
    
    $conn->commit();
    
    echo "\n✅ Migración 032 completada exitosamente\n";
    
    // Verificar resultado
    echo "\n--- Verificación ---\n";
    $checkQuery = "SELECT codigo, nombre, orden FROM catalogo_tipos_vehiculo ORDER BY orden";
    $stmt = $conn->prepare($checkQuery);
    $stmt->execute();
    $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Tipos de vehículo disponibles:\n";
    foreach ($tipos as $tipo) {
        echo "  [{$tipo['orden']}] {$tipo['codigo']}: {$tipo['nombre']}\n";
    }
    
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
