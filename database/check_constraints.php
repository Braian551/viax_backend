<?php
/**
 * Script para verificar restricciones NOT NULL en detalles_conductor
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🔍 Verificando restricciones NOT NULL en detalles_conductor...\n\n";

    $stmt = $pdo->query("DESCRIBE detalles_conductor");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Campos NOT NULL (requeridos):\n";
    $notNullFields = [];
    $nullableFields = [];

    foreach ($columns as $column) {
        if ($column['Null'] == 'NO') {
            $notNullFields[] = $column['Field'];
            echo "   - {$column['Field']}: {$column['Type']} (NOT NULL)\n";
        } else {
            $nullableFields[] = $column['Field'];
        }
    }

    echo "\nCampos NULLABLE (opcionales):\n";
    foreach ($nullableFields as $field) {
        echo "   - $field\n";
    }

    echo "\n📋 Resumen:\n";
    echo "   Total campos: " . count($columns) . "\n";
    echo "   NOT NULL: " . count($notNullFields) . "\n";
    echo "   NULLABLE: " . count($nullableFields) . "\n";

    echo "\n🎯 Campos de documentos NOT NULL que necesito manejar:\n";
    $documentNotNullFields = array_intersect($notNullFields, [
        'licencia_conduccion', 'licencia_vencimiento', 'licencia_expedicion', 'licencia_categoria',
        'vehiculo_tipo', 'vehiculo_marca', 'vehiculo_modelo', 'vehiculo_placa'
    ]);

    foreach ($documentNotNullFields as $field) {
        echo "   - $field\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>