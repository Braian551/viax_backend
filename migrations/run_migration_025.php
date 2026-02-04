<?php
/**
 * Migración 025: Sistema de Comisiones Empresa-Admin
 * 
 * Agrega campos para gestionar las comisiones que admin
 * cobra a cada empresa de transporte.
 */

require_once '../config/database.php';

echo "==============================================\n";
echo "Migración 025: Sistema de Comisiones Empresa\n";
echo "==============================================\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "Leyendo archivo SQL...\n";
    $sql = file_get_contents(__DIR__ . '/025_company_commission_system.sql');
    
    if (!$sql) {
        throw new Exception("No se pudo leer el archivo SQL");
    }
    
    echo "Ejecutando migración...\n\n";
    $conn->exec($sql);
    
    // Verificar las columnas
    echo "Verificando columnas añadidas:\n";
    $query = "SELECT column_name, data_type, column_default
              FROM information_schema.columns 
              WHERE table_name = 'empresas_transporte' 
              AND column_name IN ('comision_admin_porcentaje', 'saldo_pendiente')";
    $stmt = $conn->query($query);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $col) {
        echo "  ✅ {$col['column_name']}: {$col['data_type']} (default: {$col['column_default']})\n";
    }
    
    // Verificar tabla pagos_empresas
    echo "\nVerificando tabla pagos_empresas:\n";
    $queryTable = "SELECT COUNT(*) as count FROM information_schema.tables 
                   WHERE table_name = 'pagos_empresas'";
    $stmtTable = $conn->query($queryTable);
    $tableExists = $stmtTable->fetch(PDO::FETCH_ASSOC);
    
    if ($tableExists['count'] > 0) {
        echo "  ✅ Tabla pagos_empresas creada correctamente\n";
    }
    
    echo "\n==============================================\n";
    echo "✅ Migración 025 ejecutada exitosamente\n";
    echo "==============================================\n";
    
} catch (Exception $e) {
    echo "❌ Error en la migración: " . $e->getMessage() . "\n";
    exit(1);
}
?>
