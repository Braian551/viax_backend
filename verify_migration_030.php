<?php
require_once 'config/config.php';

$db = (new Database())->getConnection();

echo "=== VERIFICACIÓN ===\n\n";

// Contar configs
$stmt = $db->query('SELECT COUNT(*) FROM configuracion_precios WHERE empresa_id IS NOT NULL');
echo "Configuraciones de empresa en BD: " . $stmt->fetchColumn() . "\n";

// Contar tipos migrados
$stmt2 = $db->query('SELECT COUNT(*) FROM empresa_tipos_vehiculo');
echo "Tipos de vehículo migrados: " . $stmt2->fetchColumn() . "\n\n";

// Detalles de configuraciones
echo "=== EMPRESAS CON CONFIGURACIONES ===\n";
$stmt3 = $db->query("
    SELECT e.nombre, cp.tipo_vehiculo, cp.activo 
    FROM configuracion_precios cp
    JOIN empresas_transporte e ON cp.empresa_id = e.id
    WHERE cp.empresa_id IS NOT NULL
    ORDER BY e.nombre, cp.tipo_vehiculo
");

foreach ($stmt3 as $row) {
    echo "  • {$row['nombre']}: {$row['tipo_vehiculo']} - " . ($row['activo'] ? 'activo' : 'inactivo') . "\n";
}

// Migrar ahora
echo "\n=== MIGRANDO DATOS ===\n";
$migrate = $db->prepare("
    INSERT INTO empresa_tipos_vehiculo (empresa_id, tipo_vehiculo_codigo, activo, fecha_activacion)
    SELECT 
        cp.empresa_id,
        cp.tipo_vehiculo,
        CASE WHEN cp.activo = 1 THEN true ELSE false END,
        COALESCE(cp.fecha_creacion, NOW())
    FROM configuracion_precios cp
    WHERE cp.empresa_id IS NOT NULL
    AND cp.tipo_vehiculo IN (SELECT codigo FROM catalogo_tipos_vehiculo)
    ON CONFLICT (empresa_id, tipo_vehiculo_codigo) DO UPDATE
    SET activo = EXCLUDED.activo,
        actualizado_en = NOW()
");

$migrate->execute();
echo "✓ Procesados: " . $migrate->rowCount() . " registros\n";

// Verificar resultado
$stmt4 = $db->query('SELECT COUNT(*) FROM empresa_tipos_vehiculo');
echo "✓ Total en empresa_tipos_vehiculo: " . $stmt4->fetchColumn() . "\n";
