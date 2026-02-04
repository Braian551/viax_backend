<?php
/**
 * Script para ejecutar la migración 036: Tabla de pagos de comisión
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Migración 036: Tabla de pagos de comisión ===\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Leer el archivo SQL
    $sqlFile = __DIR__ . '/036_create_pagos_comision.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: $sqlFile");
    }
    
    echo "Leyendo archivo SQL: $sqlFile\n";
    $sql = file_get_contents($sqlFile);
    
    // Ejecutar el script completo ya que PDO puede manejar múltiples queries
    // pero para mejor control de errores intentamos dividir
    
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
                // Remove delimiter for some drivers if needed, but standard SQL usually needs it 
                // or PDO might want it removed depending on driver. 
                // MySQL usually accepts it if it's one statement, but for safety let's just run it.
                $db->exec($currentCommand);
                $commandCount++;
            } catch (PDOException $e) {
                // Ignorar errores de "ya existe"
                if (strpos($e->getMessage(), 'already exists') === false &&
                    strpos($e->getMessage(), 'already exists') === false &&
                    strpos($e->getMessage(), 'ER_TABLE_EXISTS_ERROR') === false) {
                    echo "⚠️ Advertencia al ejecutar comando: " . $e->getMessage() . "\n";
                    echo "Query problemático: " . substr($currentCommand, 0, 100) . "...\n";
                } else {
                    echo "ℹ️ Nota: Objeto ya existe, continuando...\n";
                }
            }
            $currentCommand = '';
        }
    }
    
    echo "✅ Comandos procesados: $commandCount\n\n";
    
    // Verificar que la tabla se creó
    echo "Verificando tabla pagos_comision...\n";
    try {
        $stmt = $db->query("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = 'pagos_comision'
            ORDER BY ordinal_position
        ");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($columns) > 0) {
            echo "Tabla 'pagos_comision' existe con las siguientes columnas:\n";
            foreach ($columns as $col) {
                echo "  ✓ {$col['column_name']} ({$col['data_type']})\n";
            }
            echo "\n✅ Migración completada exitosamente\n";
        } else {
            echo "\n❌ Error: La tabla pagos_comision no parece tener columnas.\n";
        }
    } catch (Exception $e) {
        echo "\n❌ Error al verificar tabla: " . $e->getMessage() . "\n";
    }

} catch (Exception $e) {
    echo "❌ Error Crítico: " . $e->getMessage() . "\n";
    exit(1);
}
