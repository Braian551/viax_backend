<?php
/**
 * Script para ejecutar la migración 037 - Sistema de Concurrencia y Latencia
 * 
 * Este script agrega:
 * - Optimistic locking para solicitudes
 * - Sistema de bloqueos distribuidos
 * - Cola de operaciones pendientes
 * - Log de sincronización
 */

require_once __DIR__ . '/../config/database.php';

echo "===========================================\n";
echo "Migración 037: Sistema de Concurrencia y Latencia\n";
echo "===========================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Leer el archivo SQL
    $sqlFile = __DIR__ . '/037_concurrency_and_latency_system.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo de migración no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Dividir en statements individuales (por punto y coma seguido de nueva línea)
    $statements = preg_split('/;\s*\n/', $sql);
    
    $successful = 0;
    $skipped = 0;
    $errors = 0;
    
    echo "Ejecutando migración...\n\n";
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        
        // Ignorar comentarios y líneas vacías
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        // Ignorar bloques de solo comentarios
        $cleanStatement = preg_replace('/--.*$/m', '', $statement);
        if (empty(trim($cleanStatement))) {
            continue;
        }
        
        try {
            $db->exec($statement);
            
            // Determinar qué tipo de operación fue
            if (stripos($statement, 'CREATE TABLE') !== false) {
                preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?(\w+)/i', $statement, $matches);
                $tableName = $matches[1] ?? 'unknown';
                echo "✅ Tabla creada: $tableName\n";
            } elseif (stripos($statement, 'ALTER TABLE') !== false) {
                preg_match('/ALTER TABLE\s+(\w+)/i', $statement, $matches);
                $tableName = $matches[1] ?? 'unknown';
                echo "✅ Tabla modificada: $tableName\n";
            } elseif (stripos($statement, 'CREATE INDEX') !== false) {
                preg_match('/CREATE INDEX\s+(?:IF NOT EXISTS\s+)?(\w+)/i', $statement, $matches);
                $indexName = $matches[1] ?? 'unknown';
                echo "✅ Índice creado: $indexName\n";
            } elseif (stripos($statement, 'CREATE OR REPLACE FUNCTION') !== false) {
                preg_match('/FUNCTION\s+(\w+)/i', $statement, $matches);
                $funcName = $matches[1] ?? 'unknown';
                echo "✅ Función creada: $funcName\n";
            } elseif (stripos($statement, 'CREATE TRIGGER') !== false) {
                preg_match('/TRIGGER\s+(\w+)/i', $statement, $matches);
                $triggerName = $matches[1] ?? 'unknown';
                echo "✅ Trigger creado: $triggerName\n";
            } elseif (stripos($statement, 'COMMENT ON') !== false) {
                // Comentarios silenciosos
                $skipped++;
            } else {
                $successful++;
            }
            
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();
            
            // Ignorar errores de "ya existe"
            if (strpos($errorMsg, 'already exists') !== false ||
                strpos($errorMsg, 'ya existe') !== false ||
                strpos($errorMsg, 'duplicate') !== false) {
                echo "⏭️ Ya existe, saltando...\n";
                $skipped++;
            } else {
                echo "❌ Error: $errorMsg\n";
                echo "   Statement: " . substr($statement, 0, 100) . "...\n";
                $errors++;
            }
        }
    }
    
    echo "\n===========================================\n";
    echo "Resumen de la migración:\n";
    echo "  ✅ Exitosos: $successful\n";
    echo "  ⏭️ Saltados: $skipped\n";
    echo "  ❌ Errores: $errors\n";
    echo "===========================================\n";
    
    // Verificar que las estructuras principales existen
    echo "\nVerificando estructuras creadas:\n";
    
    // Verificar columna version en solicitudes
    $stmt = $db->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'version'
    ");
    if ($stmt->fetch()) {
        echo "✅ Columna 'version' existe en solicitudes_servicio\n";
    } else {
        echo "❌ Columna 'version' NO existe\n";
    }
    
    // Verificar tabla distributed_locks
    $stmt = $db->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_name = 'distributed_locks'
        )
    ");
    if ($stmt->fetchColumn()) {
        echo "✅ Tabla 'distributed_locks' existe\n";
    } else {
        echo "❌ Tabla 'distributed_locks' NO existe\n";
    }
    
    // Verificar tabla pending_operations
    $stmt = $db->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_name = 'pending_operations'
        )
    ");
    if ($stmt->fetchColumn()) {
        echo "✅ Tabla 'pending_operations' existe\n";
    } else {
        echo "❌ Tabla 'pending_operations' NO existe\n";
    }
    
    // Verificar funciones
    $stmt = $db->query("
        SELECT proname FROM pg_proc WHERE proname IN ('acquire_lock', 'release_lock')
    ");
    $functions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('acquire_lock', $functions)) {
        echo "✅ Función 'acquire_lock' existe\n";
    }
    if (in_array('release_lock', $functions)) {
        echo "✅ Función 'release_lock' existe\n";
    }
    
    echo "\n✅ Migración 037 completada exitosamente!\n";
    
} catch (Exception $e) {
    echo "❌ Error fatal: " . $e->getMessage() . "\n";
    exit(1);
}
