<?php
/**
 * Script de migración 030: Sistema de Tipos de Vehículo por Empresa
 * 
 * Ejecuta la migración para crear las tablas normalizadas de tipos de vehículo.
 * 
 * Uso: php run_migration_030.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "============================================\n";
echo "Migración 030: Sistema de Tipos de Vehículo\n";
echo "============================================\n\n";

require_once __DIR__ . '/config/config.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "✓ Conexión a base de datos establecida\n\n";
    
    // Leer archivo SQL
    $sqlFile = __DIR__ . '/migrations/030_empresa_tipos_vehiculo.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo de migración no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    if (empty($sql)) {
        throw new Exception("Archivo de migración vacío");
    }
    
    echo "📄 Leyendo archivo de migración...\n\n";
    
    // Dividir por statements (separados por ;)
    // Pero manejando funciones que tienen múltiples ;
    $statements = [];
    $currentStatement = '';
    $inFunction = false;
    
    $lines = explode("\n", $sql);
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        
        // Ignorar líneas de comentario
        if (strpos($trimmedLine, '--') === 0) {
            continue;
        }
        
        // Detectar inicio de función
        if (preg_match('/CREATE\s+(OR\s+REPLACE\s+)?FUNCTION/i', $trimmedLine)) {
            $inFunction = true;
        }
        
        $currentStatement .= $line . "\n";
        
        // Detectar fin de función
        if ($inFunction && preg_match('/\$\$\s*LANGUAGE\s+plpgsql\s*;/i', $trimmedLine)) {
            $inFunction = false;
            $statements[] = trim($currentStatement);
            $currentStatement = '';
            continue;
        }
        
        // Si no estamos en función, ; termina el statement
        if (!$inFunction && substr($trimmedLine, -1) === ';') {
            $stmt = trim($currentStatement);
            if (!empty($stmt) && $stmt !== ';') {
                $statements[] = $stmt;
            }
            $currentStatement = '';
        }
    }
    
    // Agregar último statement si existe
    if (!empty(trim($currentStatement))) {
        $statements[] = trim($currentStatement);
    }
    
    echo "📊 " . count($statements) . " statements a ejecutar\n\n";
    
    $success = 0;
    $errors = 0;
    $errorMessages = [];
    
    foreach ($statements as $i => $statement) {
        if (empty(trim($statement))) continue;
        
        // Mostrar qué se está ejecutando (resumen)
        $preview = substr(preg_replace('/\s+/', ' ', $statement), 0, 60);
        echo "  [" . ($i + 1) . "] $preview...\n";
        
        try {
            $db->exec($statement);
            echo "      ✓ OK\n";
            $success++;
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();
            
            // Algunos errores son esperados (ya existe, etc.)
            if (strpos($errorMsg, 'already exists') !== false ||
                strpos($errorMsg, 'duplicate key') !== false ||
                strpos($errorMsg, 'already exists in schema') !== false) {
                echo "      ⚠ Ya existe (ignorado)\n";
                $success++;
            } else {
                echo "      ✗ Error: $errorMsg\n";
                $errors++;
                $errorMessages[] = [
                    'statement' => $preview,
                    'error' => $errorMsg
                ];
            }
        }
    }
    
    echo "\n============================================\n";
    echo "RESUMEN DE MIGRACIÓN\n";
    echo "============================================\n";
    echo "✓ Exitosos: $success\n";
    echo "✗ Errores:  $errors\n";
    
    if ($errors > 0) {
        echo "\nDetalles de errores:\n";
        foreach ($errorMessages as $err) {
            echo "  - {$err['statement']}\n";
            echo "    Error: {$err['error']}\n";
        }
    }
    
    // Verificar tablas creadas
    echo "\n============================================\n";
    echo "VERIFICACIÓN DE TABLAS\n";
    echo "============================================\n";
    
    $tablesToCheck = [
        'catalogo_tipos_vehiculo',
        'empresa_tipos_vehiculo',
        'empresa_tipos_vehiculo_historial',
        'empresa_vehiculo_notificaciones'
    ];
    
    foreach ($tablesToCheck as $table) {
        $check = $db->query("SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = '$table'
        )");
        $exists = $check->fetchColumn();
        
        if ($exists) {
            // Contar registros
            $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            echo "✓ $table ($count registros)\n";
        } else {
            echo "✗ $table (NO EXISTE)\n";
        }
    }
    
    // Verificar catálogo de tipos
    echo "\n============================================\n";
    echo "CATÁLOGO DE TIPOS DE VEHÍCULO\n";
    echo "============================================\n";
    
    $tipos = $db->query("SELECT codigo, nombre, descripcion FROM catalogo_tipos_vehiculo ORDER BY orden");
    foreach ($tipos as $tipo) {
        echo "  • {$tipo['codigo']}: {$tipo['nombre']} - {$tipo['descripcion']}\n";
    }
    
    // Verificar empresas migradas
    echo "\n============================================\n";
    echo "EMPRESAS CON TIPOS DE VEHÍCULO MIGRADOS\n";
    echo "============================================\n";
    
    $empresas = $db->query("
        SELECT e.nombre, COUNT(etv.id) as tipos_count,
               STRING_AGG(etv.tipo_vehiculo_codigo, ', ') as tipos
        FROM empresas_transporte e
        LEFT JOIN empresa_tipos_vehiculo etv ON e.id = etv.empresa_id
        GROUP BY e.id, e.nombre
        HAVING COUNT(etv.id) > 0
        ORDER BY e.nombre
        LIMIT 10
    ");
    
    $found = false;
    foreach ($empresas as $emp) {
        $found = true;
        echo "  • {$emp['nombre']}: {$emp['tipos_count']} tipos ({$emp['tipos']})\n";
    }
    
    if (!$found) {
        echo "  (No hay empresas con tipos migrados aún)\n";
    }
    
    echo "\n============================================\n";
    echo "✅ MIGRACIÓN COMPLETADA\n";
    echo "============================================\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR FATAL: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
