<?php
/**
 * Script para verificar la estructura correcta de detalles_conductor
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🔍 Verificando estructura completa de detalles_conductor...\n\n";

    $stmt = $pdo->query("DESCRIBE detalles_conductor");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Columnas en detalles_conductor:\n";
    foreach ($columns as $column) {
        echo "   - {$column['Field']}: {$column['Type']} ({$column['Null']})";
        if ($column['Key'] == 'PRI') echo " PRIMARY KEY";
        if ($column['Key'] == 'MUL') echo " FOREIGN KEY";
        if ($column['Default'] !== null) echo " Default: {$column['Default']}";
        echo "\n";
    }
    echo "\n";

    // Verificar datos actuales
    $stmt2 = $pdo->query("SELECT * FROM detalles_conductor LIMIT 1");
    $data = $stmt2->fetch(PDO::FETCH_ASSOC);

    if ($data) {
        echo "Datos de ejemplo en detalles_conductor:\n";
        foreach ($data as $key => $value) {
            $displayValue = $value;
            if (strlen($displayValue) > 50) {
                $displayValue = substr($displayValue, 0, 50) . "...";
            }
            echo "   - $key: $displayValue\n";
        }
    } else {
        echo "No hay datos en detalles_conductor\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>