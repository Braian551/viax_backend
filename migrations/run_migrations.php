<?php
/**
 * Script para ejecutar migraciones SQL
 * Uso: php migrations/run_migrations.php
 */

require_once __DIR__ . '/../config/database.php';

function runMigrations() {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        echo "=== Ejecutando Migraciones de Base de Datos ===\n\n";
        
        // Obtener todos los archivos SQL en orden
        $migrationFiles = glob(__DIR__ . '/*.sql');
        sort($migrationFiles);
        
        foreach ($migrationFiles as $file) {
            $filename = basename($file);
            echo "Ejecutando: $filename\n";
            
            $sql = file_get_contents($file);
            
            // Dividir por ; pero ignorar ; dentro de bloques de comentarios
            $statements = explode(';', $sql);
            
            $executedStatements = 0;
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (empty($statement)) continue;
                
                try {
                    $db->exec($statement);
                    $executedStatements++;
                } catch (PDOException $e) {
                    // Ignorar errores de "tabla ya existe"
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        echo "  ⚠ Advertencia: " . $e->getMessage() . "\n";
                    }
                }
            }
            
            echo "  ✓ Completado ($executedStatements statements ejecutados)\n\n";
        }
        
        echo "=== Migraciones completadas exitosamente ===\n";
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Ejecutar si se llama desde línea de comandos
if (php_sapi_name() === 'cli') {
    runMigrations();
}
