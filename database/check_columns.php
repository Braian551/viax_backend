<?php
/**
 * Verificar estado de las columnas de la tabla usuarios
 */

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "=== Verificando Columnas de Tabla 'usuarios' ===\n\n";
    
    // Obtener información de columnas
    $query = "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
              FROM INFORMATION_SCHEMA.COLUMNS 
              WHERE TABLE_SCHEMA = 'pingo' 
              AND TABLE_NAME = 'usuarios'
              ORDER BY ORDINAL_POSITION";
    
    $stmt = $db->query($query);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Columnas actuales:\n";
    echo str_repeat("-", 80) . "\n";
    printf("%-30s %-15s %-12s %-20s\n", "COLUMNA", "TIPO", "NULLABLE", "DEFAULT");
    echo str_repeat("-", 80) . "\n";
    
    $columnsNeeded = ['es_activo', 'es_verificado', 'foto_perfil', 'fecha_registro', 'fecha_actualizacion'];
    $columnsOld = ['activo', 'verificado', 'url_imagen_perfil', 'creado_en', 'actualizado_en'];
    
    $hasNew = [];
    $hasOld = [];
    
    foreach ($columns as $col) {
        $name = $col['COLUMN_NAME'];
        printf("%-30s %-15s %-12s %-20s\n", 
            $name, 
            $col['DATA_TYPE'], 
            $col['IS_NULLABLE'], 
            $col['COLUMN_DEFAULT'] ?? 'NULL'
        );
        
        if (in_array($name, $columnsNeeded)) {
            $hasNew[] = $name;
        }
        if (in_array($name, $columnsOld)) {
            $hasOld[] = $name;
        }
    }
    
    echo str_repeat("-", 80) . "\n\n";
    
    echo "=== Estado de la Migración ===\n\n";
    
    echo "Columnas NUEVAS encontradas (" . count($hasNew) . "/5):\n";
    foreach ($columnsNeeded as $col) {
        $status = in_array($col, $hasNew) ? "✓" : "✗";
        echo "  $status $col\n";
    }
    
    echo "\nColumnas ANTIGUAS encontradas (" . count($hasOld) . "/5):\n";
    foreach ($columnsOld as $col) {
        $status = in_array($col, $hasOld) ? "⚠" : "✓";
        echo "  $status $col " . (in_array($col, $hasOld) ? "(DEBE ELIMINARSE)" : "(Ya eliminada)") . "\n";
    }
    
    echo "\n" . str_repeat("=", 80) . "\n";
    
    if (count($hasNew) == 5 && count($hasOld) == 0) {
        echo "\n✅ MIGRACIÓN COMPLETADA EXITOSAMENTE\n";
        echo "Todas las columnas nuevas existen y las antiguas fueron eliminadas.\n\n";
    } elseif (count($hasOld) > 0) {
        echo "\n⚠️  MIGRACIÓN PENDIENTE\n";
        echo "Aún existen " . count($hasOld) . " columnas antiguas que deben renombrarse.\n";
        echo "\nEjecuta: php migrations/apply_migration_003.php\n\n";
    } else {
        echo "\n⚠️  ESTADO INCONSISTENTE\n";
        echo "Algunas columnas nuevas faltan. Verifica la estructura manualmente.\n\n";
    }
    
    // Contar usuarios
    $countQuery = "SELECT COUNT(*) as total FROM usuarios";
    $stmt = $db->query($countQuery);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Total de usuarios en la base de datos: " . $result['total'] . "\n\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
