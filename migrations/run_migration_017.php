<?php
/**
 * Script para ejecutar la migración 017 - Corrección del Sistema de Pagos
 */

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "=== Ejecutando migración 017: Corrección del Sistema de Pagos ===\n\n";
    
    // Leer y ejecutar el archivo SQL
    $sql = file_get_contents(__DIR__ . '/017_fix_payment_system.sql');
    
    // Dividir por punto y coma pero ignorar los comentarios
    $statements = array_filter(
        array_map('trim', preg_split('/;(?=\s*(?:--|ALTER|CREATE|UPDATE|COMMENT))/', $sql)),
        fn($s) => !empty($s) && !str_starts_with(trim($s), '--')
    );
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || str_starts_with($statement, '--')) {
            continue;
        }
        
        try {
            $db->exec($statement);
            
            // Detectar qué tipo de operación es para mostrar mensaje apropiado
            if (stripos($statement, 'ALTER TABLE') !== false && stripos($statement, 'ADD COLUMN') !== false) {
                preg_match('/ADD COLUMN.*?(\w+)\s/', $statement, $matches);
                $col = $matches[1] ?? 'columna';
                echo "✅ Columna '$col' agregada\n";
            } elseif (stripos($statement, 'CREATE INDEX') !== false) {
                preg_match('/INDEX.*?(\w+)\s+ON/', $statement, $matches);
                echo "✅ Índice '{$matches[1]}' creado\n";
            } elseif (stripos($statement, 'CREATE TABLE') !== false) {
                preg_match('/TABLE.*?(\w+)\s*\(/', $statement, $matches);
                echo "✅ Tabla '{$matches[1]}' creada\n";
            } elseif (stripos($statement, 'UPDATE') !== false) {
                echo "✅ Datos actualizados\n";
            }
            
            $successCount++;
        } catch (PDOException $e) {
            // Ignorar errores de "ya existe"
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'ya existe') !== false ||
                strpos($e->getMessage(), 'duplicate') !== false) {
                echo "⏭️  Ya existe, saltando...\n";
                $successCount++;
            } else {
                echo "❌ Error: " . $e->getMessage() . "\n";
                $errorCount++;
            }
        }
    }
    
    echo "\n=== Resumen ===\n";
    echo "Operaciones exitosas: $successCount\n";
    echo "Errores: $errorCount\n";
    
    // Verificación final
    echo "\n=== Verificación de estructura ===\n";
    
    // Verificar columnas de solicitudes_servicio
    $checkCols = ['precio_estimado', 'precio_final', 'metodo_pago', 'pago_confirmado'];
    foreach ($checkCols as $col) {
        $stmt = $db->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'solicitudes_servicio' AND column_name = ?)");
        $stmt->execute([$col]);
        $exists = $stmt->fetchColumn();
        echo "solicitudes_servicio.$col: " . ($exists ? '✅' : '❌') . "\n";
    }
    
    // Verificar columnas de transacciones
    $checkCols = ['monto_conductor', 'estado', 'comision_plataforma'];
    foreach ($checkCols as $col) {
        $stmt = $db->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'transacciones' AND column_name = ?)");
        $stmt->execute([$col]);
        $exists = $stmt->fetchColumn();
        echo "transacciones.$col: " . ($exists ? '✅' : '❌') . "\n";
    }
    
    // Verificar tabla pagos_viaje
    $stmt = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'pagos_viaje')");
    echo "tabla pagos_viaje: " . ($stmt->fetchColumn() ? '✅' : '❌') . "\n";
    
    echo "\n✅ Migración 017 completada!\n";
    
} catch (Exception $e) {
    echo "❌ Error fatal: " . $e->getMessage() . "\n";
    exit(1);
}
