<?php
/**
 * run_migration_031.php
 * Ejecuta la migración del sistema de soporte
 */

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "Ejecutando migración 031: Sistema de Soporte...\n";
    
    $sql = file_get_contents(__DIR__ . '/031_support_system.sql');
    
    $conn->exec($sql);
    
    echo "✅ Migración 031 ejecutada exitosamente\n";
    
    // Verificar tablas creadas
    $tables = ['categorias_soporte', 'tickets_soporte', 'mensajes_ticket', 'solicitudes_callback'];
    foreach ($tables as $table) {
        $stmt = $conn->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "  - $table: $count registros\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
