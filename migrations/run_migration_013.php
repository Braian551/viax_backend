<?php
/**
 * Script para ejecutar la migración 013 - Mensajes de Chat
 */

require_once __DIR__ . '/../config/database.php';

function run_migration_013() {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        echo "=== Ejecutando Migración 013: Mensajes de Chat ===\n\n";
        
        // Leer el archivo SQL
        $sqlFile = __DIR__ . '/013_create_mensajes_chat.sql';
        if (!file_exists($sqlFile)) {
            throw new Exception("Archivo de migración no encontrado: $sqlFile");
        }
        
        $sql = file_get_contents($sqlFile);
        
        // Ejecutar el SQL completo (PostgreSQL maneja múltiples statements)
        $db->exec($sql);
        
        echo "✓ Tabla mensajes_chat creada exitosamente\n";
        echo "✓ Índices creados exitosamente\n";
        echo "✓ Trigger de actualización creado exitosamente\n";
        
        // Verificar que la tabla existe
        $stmt = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'mensajes_chat'");
        $exists = $stmt->fetchColumn() > 0;
        
        if ($exists) {
            echo "\n✅ Migración 013 completada exitosamente!\n";
            
            // Mostrar estructura de la tabla
            echo "\n=== Estructura de la tabla mensajes_chat ===\n";
            $stmt = $db->query("
                SELECT column_name, data_type, is_nullable, column_default 
                FROM information_schema.columns 
                WHERE table_name = 'mensajes_chat' 
                ORDER BY ordinal_position
            ");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($columns as $col) {
                echo "- {$col['column_name']}: {$col['data_type']}";
                if ($col['is_nullable'] === 'NO') echo " NOT NULL";
                if ($col['column_default']) echo " DEFAULT {$col['column_default']}";
                echo "\n";
            }
        } else {
            throw new Exception("La tabla no se creó correctamente");
        }
        
    } catch (PDOException $e) {
        // Ignorar errores si la tabla ya existe
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "⚠ La tabla mensajes_chat ya existe (ignorando)\n";
            echo "✅ Migración 013 completada (tabla existente)\n";
        } else {
            echo "❌ Error PDO: " . $e->getMessage() . "\n";
            exit(1);
        }
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Ejecutar si se llama desde línea de comandos
if (php_sapi_name() === 'cli') {
    run_migration_013();
}
