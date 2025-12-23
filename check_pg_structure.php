<?php
// Script para verificar la estructura de las tablas en PostgreSQL

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "=== TIPOS DE DATOS IMPORTANTES ===\n\n";
    
    // Verificar tipo de es_activo en usuarios
    $stmt = $db->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_name = 'usuarios' 
        AND column_name IN ('es_activo', 'tipo_usuario')
    ");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Usuarios:\n";
    print_r($cols);
    
    // Verificar tipo de disponible en detalles_conductor
    $stmt = $db->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_name = 'detalles_conductor' 
        AND column_name IN ('disponible', 'estado_verificacion', 'vehiculo_tipo')
    ");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\nDetalles Conductor:\n";
    print_r($cols);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
