<?php
/**
 * Script para verificar las tablas disponibles en la base de datos
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🔍 Verificando tablas disponibles en la base de datos...\n\n";

    // Obtener todas las tablas
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_NUM);

    echo "Tablas encontradas:\n";
    foreach ($tables as $table) {
        echo "   - {$table[0]}\n";
    }

    echo "\n";

    // Buscar tablas relacionadas con conductores
    echo "Tablas relacionadas con conductores:\n";
    $conductorTables = [];
    foreach ($tables as $table) {
        if (stripos($table[0], 'conductor') !== false) {
            $conductorTables[] = $table[0];
            echo "   - {$table[0]}\n";
        }
    }

    if (empty($conductorTables)) {
        echo "   No se encontraron tablas relacionadas con conductores\n";
    }

} catch (Exception $e) {
    echo "❌ Error durante la verificación: " . $e->getMessage() . "\n";
    exit(1);
}
?>