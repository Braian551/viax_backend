<?php
/**
 * Script para ejecutar la migración 001 (Postgres-ready)
 * Uso: php migrations/run_migration_001.php
 */

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $file = __DIR__ . '/001_create_conductores_confianza_tables.sql';
    echo "=== Ejecutando migración: $file ===\n\n";

    if (!file_exists($file)) {
        throw new Exception("Archivo de migración no encontrado: $file");
    }

    $sql = file_get_contents($file);

    // Ejecutar todo el contenido de la migración de una vez
    $db->exec($sql);
    echo "✅ Migración 001 ejecutada correctamente.\n";

} catch (PDOException $e) {
    echo "❌ Error en la migración: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

?>
