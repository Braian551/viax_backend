<?php
/**
 * Helpers de esquema para endpoints de tracking.
 *
 * Permiten validar existencia de tablas/columnas en tiempo de ejecucion
 * para compatibilidad entre despliegues con diferentes migraciones.
 */

declare(strict_types=1);

/**
 * Verifica si existe una tabla en el esquema public.
 */
function trackingTableExists(PDO $db, string $tableName): bool {
    static $cache = [];
    $key = strtolower(trim($tableName));
    if ($key === '') {
        return false;
    }

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $db->prepare("SELECT to_regclass(:table_name) IS NOT NULL");
    $stmt->execute([':table_name' => 'public.' . $key]);
    $exists = (bool)$stmt->fetchColumn();
    $cache[$key] = $exists;

    return $exists;
}

/**
 * Verifica si existe una columna en una tabla del esquema public.
 */
function trackingColumnExists(PDO $db, string $tableName, string $columnName): bool {
    static $cache = [];

    $table = strtolower(trim($tableName));
    $column = strtolower(trim($columnName));
    if ($table === '' || $column === '') {
        return false;
    }

    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $db->prepare(" 
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table_name
              AND column_name = :column_name
        )
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    $exists = (bool)$stmt->fetchColumn();
    $cache[$cacheKey] = $exists;

    return $exists;
}
