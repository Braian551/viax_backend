<?php
/**
 * Script para ejecutar la migración 010 - Arreglar secuencias de PostgreSQL
 * 
 * Este script crea las secuencias necesarias para las columnas ID 
 * que en MySQL usaban AUTO_INCREMENT
 */

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "=== Ejecutando migración 010: Arreglar secuencias PostgreSQL ===\n\n";
    
    // Lista de tablas que necesitan secuencias
    $tables = [
        'solicitudes_servicio',
        'asignaciones_conductor',
        'calificaciones',
        'configuracion_precios',
        'configuraciones_app',
        'detalles_conductor',
        'transacciones',
        'ubicaciones_usuario',
        'usuarios',
        'paradas_solicitud',
        'cache_direcciones',
        'cache_geocodificacion',
        'dispositivos_usuario',
        'administradores',
        'documentos_conductor'
    ];
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($tables as $table) {
        try {
            // Verificar si la tabla existe
            $checkTable = $db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = '$table')");
            $tableExists = $checkTable->fetchColumn();
            
            if (!$tableExists) {
                echo "⏭️  Tabla '$table' no existe, saltando...\n";
                continue;
            }
            
            $seqName = "{$table}_id_seq";
            
            // Crear la secuencia si no existe
            $db->exec("CREATE SEQUENCE IF NOT EXISTS $seqName");
            
            // Obtener el valor máximo actual
            $maxIdStmt = $db->query("SELECT COALESCE(MAX(id), 0) FROM $table");
            $maxId = (int)$maxIdStmt->fetchColumn();
            
            // Establecer el valor de la secuencia
            $db->exec("SELECT setval('$seqName', " . ($maxId + 1) . ", false)");
            
            // Establecer el valor por defecto
            $db->exec("ALTER TABLE $table ALTER COLUMN id SET DEFAULT nextval('$seqName')");
            
            // Vincular la secuencia a la columna
            $db->exec("ALTER SEQUENCE $seqName OWNED BY $table.id");
            
            echo "✅ Tabla '$table' - Secuencia creada y vinculada (max_id: $maxId)\n";
            $successCount++;
            
        } catch (Exception $e) {
            echo "❌ Error en tabla '$table': " . $e->getMessage() . "\n";
            $errorCount++;
        }
    }
    
    echo "\n=== Resumen ===\n";
    echo "Tablas procesadas correctamente: $successCount\n";
    echo "Errores: $errorCount\n";
    
    // Verificar que la secuencia de solicitudes_servicio funciona
    echo "\n=== Verificación de solicitudes_servicio ===\n";
    $checkSeq = $db->query("SELECT column_default FROM information_schema.columns WHERE table_name = 'solicitudes_servicio' AND column_name = 'id'");
    $default = $checkSeq->fetchColumn();
    echo "Valor DEFAULT de id: $default\n";
    
    echo "\n✅ Migración completada exitosamente!\n";
    
} catch (Exception $e) {
    echo "❌ Error fatal: " . $e->getMessage() . "\n";
    exit(1);
}
