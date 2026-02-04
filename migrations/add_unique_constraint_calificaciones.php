<?php
/**
 * Migración: Agregar UNIQUE constraint a calificaciones
 * 
 * Previene duplicados a nivel de base de datos cuando un usuario
 * intenta calificar múltiples veces el mismo viaje.
 * 
 * El constraint asegura que solo puede existir una calificación
 * por combinación de (solicitud_id, usuario_calificador_id).
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Migración: Agregar UNIQUE constraint a calificaciones ===\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar si el constraint ya existe
    $stmt = $db->prepare("
        SELECT constraint_name 
        FROM information_schema.table_constraints 
        WHERE table_name = 'calificaciones' 
        AND constraint_type = 'UNIQUE'
        AND constraint_name = 'unique_calificacion_por_usuario_solicitud'
    ");
    $stmt->execute();
    
    if ($stmt->fetch()) {
        echo "✅ El UNIQUE constraint 'unique_calificacion_por_usuario_solicitud' ya existe.\n";
        exit(0);
    }
    
    // Primero, eliminar duplicados existentes (mantener el más reciente)
    echo "🔍 Buscando duplicados existentes...\n";
    
    $stmt = $db->prepare("
        SELECT solicitud_id, usuario_calificador_id, COUNT(*) as count
        FROM calificaciones
        GROUP BY solicitud_id, usuario_calificador_id
        HAVING COUNT(*) > 1
    ");
    $stmt->execute();
    $duplicados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($duplicados) > 0) {
        echo "⚠️  Encontrados " . count($duplicados) . " grupos con duplicados.\n";
        echo "   Eliminando duplicados (manteniendo el más reciente)...\n";
        
        foreach ($duplicados as $dup) {
            // Mantener solo el registro más reciente
            $stmt = $db->prepare("
                DELETE FROM calificaciones 
                WHERE solicitud_id = ? 
                AND usuario_calificador_id = ?
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id 
                        FROM calificaciones 
                        WHERE solicitud_id = ? 
                        AND usuario_calificador_id = ?
                        ORDER BY creado_en DESC 
                        LIMIT 1
                    ) as subquery
                )
            ");
            $stmt->execute([
                $dup['solicitud_id'], 
                $dup['usuario_calificador_id'],
                $dup['solicitud_id'],
                $dup['usuario_calificador_id']
            ]);
            
            echo "   - Limpiados duplicados para solicitud {$dup['solicitud_id']}, usuario {$dup['usuario_calificador_id']}\n";
        }
        
        echo "✅ Duplicados eliminados.\n\n";
    } else {
        echo "✅ No se encontraron duplicados.\n\n";
    }
    
    // Crear el UNIQUE constraint
    echo "📝 Creando UNIQUE constraint...\n";
    
    $db->exec("
        ALTER TABLE calificaciones 
        ADD CONSTRAINT unique_calificacion_por_usuario_solicitud 
        UNIQUE (solicitud_id, usuario_calificador_id)
    ");
    
    echo "✅ UNIQUE constraint 'unique_calificacion_por_usuario_solicitud' creado exitosamente.\n";
    
    // Crear índice para mejorar performance de búsqueda
    echo "\n📝 Creando índice para búsqueda por solicitud y calificador...\n";
    
    // Verificar si el índice ya existe
    $stmt = $db->prepare("
        SELECT indexname 
        FROM pg_indexes 
        WHERE tablename = 'calificaciones' 
        AND indexname = 'idx_calificaciones_solicitud_calificador'
    ");
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        $db->exec("
            CREATE INDEX idx_calificaciones_solicitud_calificador 
            ON calificaciones (solicitud_id, usuario_calificador_id)
        ");
        echo "✅ Índice 'idx_calificaciones_solicitud_calificador' creado.\n";
    } else {
        echo "✅ Índice ya existe.\n";
    }
    
    echo "\n=== Migración completada exitosamente ===\n";
    
} catch (PDOException $e) {
    echo "❌ Error de base de datos: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
