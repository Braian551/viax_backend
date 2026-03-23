<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/SensitiveDataCrypto.php';

/**
 * Migra datos legacy en texto plano a formato cifrado.
 * Ejecutar solo en ventana controlada de despliegue.
 */

function migrateColumn(PDO $db, string $table, string $idColumn, string $column): int
{
    $query = sprintf("SELECT %s, %s FROM %s WHERE %s IS NOT NULL AND TRIM(%s) <> ''", $idColumn, $column, $table, $column, $column);
    $stmt = $db->query($query);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    $updateSql = sprintf('UPDATE %s SET %s = :value WHERE %s = :id', $table, $column, $idColumn);
    $upStmt = $db->prepare($updateSql);

    foreach ($rows as $row) {
        $id = $row[$idColumn] ?? null;
        $raw = $row[$column] ?? null;
        if ($id === null || $raw === null) {
            continue;
        }

        $raw = trim((string) $raw);
        if ($raw === '' || isSensitiveDataEncrypted($raw)) {
            continue;
        }

        $encrypted = encryptSensitiveData($raw);
        $upStmt->execute([
            ':value' => $encrypted,
            ':id' => $id,
        ]);
        $updated++;
    }

    return $updated;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $db->beginTransaction();

    $total = 0;
    $total += migrateColumn($db, 'admin_configuracion_banco', 'id', 'numero_cuenta');
    $total += migrateColumn($db, 'empresas_configuracion', 'empresa_id', 'numero_cuenta');
    $total += migrateColumn($db, 'pagos_comision_reportes', 'id', 'numero_cuenta_destino');
    $total += migrateColumn($db, 'pagos_empresa_reportes', 'id', 'numero_cuenta_destino');

    $db->commit();

    echo json_encode([
        'success' => true,
        'updated_rows' => $total,
        'message' => 'Migración de cifrado financiero completada',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
