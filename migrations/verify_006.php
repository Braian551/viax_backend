<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "=== Verificando columnas de fotos en detalles_conductor ===\n\n";
    
    $stmt = $db->query("DESCRIBE detalles_conductor");
    $fotoCols = [];
    
    while($row = $stmt->fetch()) {
        if(strpos($row['Field'], 'foto') !== false) {
            $fotoCols[] = $row;
            echo "✓ " . $row['Field'] . " - " . $row['Type'] . "\n";
        }
    }
    
    echo "\n=== Total columnas de foto: " . count($fotoCols) . " ===\n\n";
    
    echo "=== Verificando tabla documentos_conductor_historial ===\n\n";
    
    $stmt = $db->query("SHOW TABLES LIKE 'documentos_conductor_historial'");
    if($stmt->rowCount() > 0) {
        echo "✓ Tabla documentos_conductor_historial existe\n\n";
        
        $stmt = $db->query("DESCRIBE documentos_conductor_historial");
        while($row = $stmt->fetch()) {
            echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    } else {
        echo "✗ Tabla documentos_conductor_historial NO existe\n";
    }
    
    echo "\n=== Verificación completada ===\n";
    
} catch(Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
