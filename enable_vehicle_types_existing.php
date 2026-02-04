<?php
/**
 * Script para habilitar tipos de vehículo a empresas existentes
 */

require_once 'config/config.php';

$db = (new Database())->getConnection();

echo "=== HABILITANDO TIPOS DE VEHÍCULO PARA EMPRESAS EXISTENTES ===\n\n";

// Obtener empresas activas sin tipos de vehículo
$query = "SELECT e.id, e.nombre, e.estado 
          FROM empresas_transporte e
          WHERE e.estado IN ('activo', 'pendiente')
          AND NOT EXISTS (
              SELECT 1 FROM empresa_tipos_vehiculo etv 
              WHERE etv.empresa_id = e.id
          )
          ORDER BY e.nombre";

$stmt = $db->query($query);
$empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($empresas)) {
    echo "No hay empresas sin tipos de vehículo.\n";
    exit;
}

echo "Empresas encontradas: " . count($empresas) . "\n\n";

foreach ($empresas as $empresa) {
    echo "Empresa: {$empresa['nombre']} (ID: {$empresa['id']}, Estado: {$empresa['estado']})\n";
    
    // Habilitar todos los tipos
    $tipos = ['moto', 'auto', 'motocarro'];
    $insertStmt = $db->prepare("
        INSERT INTO empresa_tipos_vehiculo 
            (empresa_id, tipo_vehiculo_codigo, activo, fecha_activacion)
        VALUES (?, ?, true, NOW())
        ON CONFLICT (empresa_id, tipo_vehiculo_codigo) DO NOTHING
    ");
    
    $count = 0;
    foreach ($tipos as $tipo) {
        $insertStmt->execute([$empresa['id'], $tipo]);
        if ($insertStmt->rowCount() > 0) {
            $count++;
            echo "  ✓ Habilitado: $tipo\n";
        }
    }
    
    echo "  Total habilitados: $count\n\n";
}

echo "=== PROCESO COMPLETADO ===\n";
