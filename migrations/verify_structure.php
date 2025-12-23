<?php
/**
 * Verify database structure for vehicle registration
 */

require_once '../config/database.php';

echo "=== Verifying Database Structure ===\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check table structure
    $stmt = $db->query("DESCRIBE detalles_conductor");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $requiredColumns = [
        'licencia_conduccion',
        'licencia_expedicion',
        'licencia_vencimiento',
        'licencia_categoria',
        'vehiculo_tipo',
        'vehiculo_marca',
        'vehiculo_modelo',
        'vehiculo_anio',
        'vehiculo_color',
        'vehiculo_placa',
        'aseguradora',
        'numero_poliza_seguro',
        'vencimiento_seguro',
        'soat_numero',
        'soat_vencimiento',
        'tecnomecanica_numero',
        'tecnomecanica_vencimiento',
        'tarjeta_propiedad_numero'
    ];
    
    echo "Checking required columns:\n\n";
    
    $existingColumns = array_column($columns, 'Field');
    $allPresent = true;
    
    foreach ($requiredColumns as $column) {
        $exists = in_array($column, $existingColumns);
        echo sprintf("  %-35s %s\n", $column, $exists ? '✓' : '✗ MISSING');
        if (!$exists) {
            $allPresent = false;
        }
    }
    
    echo "\n";
    
    if ($allPresent) {
        echo "✓ All required columns are present!\n";
        echo "\nDatabase is ready for vehicle registration.\n";
    } else {
        echo "✗ Some columns are missing. Please run the migration.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
