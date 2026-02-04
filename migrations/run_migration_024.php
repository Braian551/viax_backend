<?php
/**
 * Migración 024: Establecer Comisiones a Cero
 * 
 * Actualiza las comisiones a 0% ya que por el momento
 * el método de pago es solo efectivo y no se cobrará
 * comisión al conductor.
 */

require_once '../config/database.php';

echo "==============================================\n";
echo "Migración 024: Establecer Comisiones a Cero\n";
echo "==============================================\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Mostrar estado actual
    echo "Estado actual de las comisiones:\n";
    $query = "SELECT id, tipo_vehiculo, comision_plataforma, comision_metodo_pago FROM configuracion_precios";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $before = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($before as $row) {
        echo "  - {$row['tipo_vehiculo']}: Plataforma={$row['comision_plataforma']}%, Método Pago={$row['comision_metodo_pago']}%\n";
    }
    
    echo "\nActualizando comisiones a 0...\n";
    
    // Ejecutar la migración
    $updateQuery = "UPDATE configuracion_precios 
                    SET comision_plataforma = 0,
                        comision_metodo_pago = 0,
                        notas = CONCAT(COALESCE(notas, ''), ' | Comisiones establecidas a 0 - Dic 2025'),
                        fecha_actualizacion = NOW()";
    
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->execute();
    
    $rowCount = $updateStmt->rowCount();
    echo "✅ Registros actualizados: $rowCount\n\n";
    
    // Verificar cambios
    echo "Estado después de la migración:\n";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $after = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($after as $row) {
        echo "  - {$row['tipo_vehiculo']}: Plataforma={$row['comision_plataforma']}%, Método Pago={$row['comision_metodo_pago']}%\n";
    }
    
    echo "\n==============================================\n";
    echo "✅ Migración 024 ejecutada exitosamente\n";
    echo "==============================================\n";
    
} catch (Exception $e) {
    echo "❌ Error en la migración: " . $e->getMessage() . "\n";
    exit(1);
}
?>
