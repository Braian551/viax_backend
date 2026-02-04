<?php
/**
 * Script para ejecutar la migración 035: Agregar columnas de desglose de precio
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Migración 035: Columnas de desglose de precio ===\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Leer el archivo SQL
    $sqlFile = __DIR__ . '/035_add_price_breakdown_columns.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Dividir por líneas y filtrar comentarios para ejecutar comando por comando
    $lines = explode("\n", $sql);
    $currentCommand = '';
    $commandCount = 0;
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        
        // Saltar comentarios y líneas vacías
        if (empty($trimmedLine) || strpos($trimmedLine, '--') === 0) {
            continue;
        }
        
        $currentCommand .= ' ' . $line;
        
        // Si la línea termina con ; es el fin de un comando
        if (substr($trimmedLine, -1) === ';') {
            try {
                $db->exec($currentCommand);
                $commandCount++;
            } catch (PDOException $e) {
                // Ignorar errores de "ya existe" para columnas/índices
                if (strpos($e->getMessage(), 'already exists') === false &&
                    strpos($e->getMessage(), 'ya existe') === false &&
                    strpos($e->getMessage(), 'duplicate') === false) {
                    echo "⚠️ Advertencia: " . $e->getMessage() . "\n";
                }
            }
            $currentCommand = '';
        }
    }
    
    echo "✅ Migración completada exitosamente. Comandos ejecutados: $commandCount\n\n";
    
    // Verificar columnas agregadas
    echo "Verificando columnas agregadas en viaje_resumen_tracking...\n";
    $stmt = $db->query("
        SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_name = 'viaje_resumen_tracking' 
        AND column_name IN (
            'tarifa_base', 'precio_distancia', 'precio_tiempo',
            'recargo_nocturno', 'recargo_hora_pico', 'recargo_festivo',
            'comision_plataforma_porcentaje', 'comision_plataforma_valor',
            'ganancia_conductor', 'tipo_recargo', 'aplico_tarifa_minima'
        )
        ORDER BY column_name
    ");
    
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($columns) > 0) {
        echo "Columnas encontradas:\n";
        foreach ($columns as $col) {
            echo "  ✓ {$col['column_name']} ({$col['data_type']})\n";
        }
    } else {
        echo "⚠️ No se encontraron las nuevas columnas\n";
    }
    
    // Verificar columna en solicitudes_servicio
    echo "\nVerificando columna en solicitudes_servicio...\n";
    $stmt = $db->query("
        SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'desglose_precio'
    ");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        echo "  ✓ {$col['column_name']} ({$col['data_type']})\n";
    } else {
        echo "  ⚠️ Columna desglose_precio no encontrada\n";
    }
    
    echo "\n✅ Verificación completada\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
