<?php
require_once 'config/database.php';

try {
    $db = (new Database())->getConnection();
    $stmt = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'detalles_conductor' ORDER BY ordinal_position");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "=== COLUMNAS DE detalles_conductor ===\n";
    foreach ($columns as $col) {
        echo "- {$col['column_name']} ({$col['data_type']})\n";
    }
    
    // Check documentos_conductor_historial
    $stmt2 = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'documentos_conductor_historial' ORDER BY ordinal_position");
    $columns2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n=== COLUMNAS DE documentos_conductor_historial ===\n";
    foreach ($columns2 as $col) {
        echo "- {$col['column_name']} ({$col['data_type']})\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
