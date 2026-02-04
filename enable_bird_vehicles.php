<?php
require_once 'config/config.php';
$db = (new Database())->getConnection();

$empresaId = 1; // Bird
$tipos = ['moto', 'auto', 'motocarro'];

$stmt = $db->prepare("
    INSERT INTO empresa_tipos_vehiculo (empresa_id, tipo_vehiculo_codigo, activo, fecha_activacion)
    VALUES (?, ?, true, NOW())
    ON CONFLICT (empresa_id, tipo_vehiculo_codigo) DO NOTHING
");

$count = 0;
foreach ($tipos as $tipo) {
    $stmt->execute([$empresaId, $tipo]);
    if ($stmt->rowCount() > 0) {
        echo "✓ Habilitado: $tipo\n";
        $count++;
    } else {
        echo "  Ya existe: $tipo\n";
    }
}

echo "\nTotal habilitados para Bird: $count\n";

// Verificar
$check = $db->query("SELECT tipo_vehiculo_codigo, activo FROM empresa_tipos_vehiculo WHERE empresa_id = 1");
echo "\nTipos de Bird:\n";
foreach ($check as $row) {
    $estado = $row['activo'] ? 'ACTIVO' : 'inactivo';
    echo "  - {$row['tipo_vehiculo_codigo']}: $estado\n";
}
