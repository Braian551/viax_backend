<?php
/**
 * Script para verificar la estructura de las tablas de documentos
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🔍 Verificando estructura de tablas de documentos...\n\n";

    // Verificar estructura de documentos_conductor_historial
    echo "1. Estructura de documentos_conductor_historial:\n";
    $stmt1 = $pdo->query("DESCRIBE documentos_conductor_historial");
    $columns1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    if (count($columns1) > 0) {
        echo "   Columnas encontradas:\n";
        foreach ($columns1 as $column) {
            echo "   - {$column['Field']}: {$column['Type']} ({$column['Null']})";
            if ($column['Key'] == 'PRI') echo " PRIMARY KEY";
            if ($column['Key'] == 'MUL') echo " FOREIGN KEY";
            if ($column['Default'] !== null) echo " Default: {$column['Default']}";
            echo "\n";
        }
    } else {
        echo "   ❌ La tabla documentos_conductor_historial NO existe\n";
    }
    echo "\n";

    // Verificar estructura de detalles_conductor (solo columnas de documentos)
    echo "2. Columnas de documentos en detalles_conductor:\n";
    $stmt2 = $pdo->query("DESCRIBE detalles_conductor");
    $columns2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $documentColumns = ['licencia_foto_url', 'soat_foto_url', 'tecnomecanica_foto_url', 'tarjeta_propiedad_foto_url', 'seguro_foto_url'];
    $foundColumns = [];

    foreach ($columns2 as $column) {
        if (in_array($column['Field'], $documentColumns)) {
            $foundColumns[] = $column['Field'];
            echo "   - {$column['Field']}: {$column['Type']} ({$column['Null']})";
            if ($column['Default'] !== null) echo " Default: {$column['Default']}";
            echo "\n";
        }
    }

    $missingColumns = array_diff($documentColumns, $foundColumns);
    if (count($missingColumns) > 0) {
        echo "\n   ❌ Columnas faltantes: " . implode(', ', $missingColumns) . "\n";
    } else {
        echo "\n   ✅ Todas las columnas de documentos están presentes\n";
    }

    echo "\n";

    // Verificar datos actuales
    echo "3. Datos actuales en las tablas:\n";

    // Historial
    $stmt3 = $pdo->query("SELECT COUNT(*) as total FROM documentos_conductor_historial");
    $historialCount = $stmt3->fetch()['total'];
    echo "   documentos_conductor_historial: $historialCount registros\n";

    // Detalles conductor con documentos
    $stmt4 = $pdo->query("
        SELECT COUNT(*) as total_con_documentos
        FROM detalles_conductor
        WHERE licencia_foto_url IS NOT NULL
           OR soat_foto_url IS NOT NULL
           OR tecnomecanica_foto_url IS NOT NULL
           OR tarjeta_propiedad_foto_url IS NOT NULL
           OR seguro_foto_url IS NOT NULL
    ");
    $documentosCount = $stmt4->fetch()['total_con_documentos'];
    echo "   detalles_conductor con documentos: $documentosCount registros\n";

    echo "\n🔍 Verificación de estructura completada.\n";

} catch (Exception $e) {
    echo "❌ Error durante la verificación: " . $e->getMessage() . "\n";
    exit(1);
}
?>