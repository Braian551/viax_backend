<?php
/**
 * Script para verificar dónde está el campo estado_verificacion
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "🔍 Buscando campo estado_verificacion...\n\n";

    // Verificar en tabla usuarios
    echo "1. Verificando tabla 'usuarios':\n";
    $stmt1 = $pdo->query("DESCRIBE usuarios");
    $columns1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    $foundInUsuarios = false;
    foreach ($columns1 as $column) {
        if (stripos($column['Field'], 'estado') !== false || stripos($column['Field'], 'verif') !== false) {
            echo "   - {$column['Field']}: {$column['Type']} ({$column['Null']})\n";
            if (stripos($column['Field'], 'estado_verif') !== false) {
                $foundInUsuarios = true;
            }
        }
    }

    if (!$foundInUsuarios) {
        echo "   No se encontró campo relacionado con verificación en 'usuarios'\n";
    }
    echo "\n";

    // Verificar en tabla detalles_conductor
    echo "2. Verificando tabla 'detalles_conductor':\n";
    $stmt2 = $pdo->query("DESCRIBE detalles_conductor");
    $columns2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $foundInDetalles = false;
    foreach ($columns2 as $column) {
        if (stripos($column['Field'], 'estado') !== false || stripos($column['Field'], 'verif') !== false) {
            echo "   - {$column['Field']}: {$column['Type']} ({$column['Null']})\n";
            if (stripos($column['Field'], 'estado_verif') !== false) {
                $foundInDetalles = true;
            }
        }
    }

    if (!$foundInDetalles) {
        echo "   No se encontró campo relacionado con verificación en 'detalles_conductor'\n";
    }

    echo "\n";

    // Verificar datos actuales en ambas tablas
    echo "3. Verificando datos actuales:\n";

    // En usuarios
    $stmt3 = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE tipo_usuario = 'conductor'");
    $conductoresUsuarios = $stmt3->fetch()['total'];
    echo "   Conductores en tabla 'usuarios': $conductoresUsuarios\n";

    // En detalles_conductor
    $stmt4 = $pdo->query("SELECT COUNT(*) as total FROM detalles_conductor");
    $conductoresDetalles = $stmt4->fetch()['total'];
    echo "   Registros en tabla 'detalles_conductor': $conductoresDetalles\n";

} catch (Exception $e) {
    echo "❌ Error durante la verificación: " . $e->getMessage() . "\n";
    exit(1);
}
?>