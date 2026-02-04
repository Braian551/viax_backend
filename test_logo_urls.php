<?php
require_once __DIR__ . '/user/services/CompanyService.php';

$_SERVER['HTTP_HOST'] = 'viax-production.up.railway.app';
$_SERVER['HTTPS'] = 'on';

$service = new CompanyService();
$response = $service->getCompaniesByMunicipality('Cañasgordas', 6.7, -75.9, 5, 10);

echo "=== Test de URLs de logos ===\n\n";

if (!empty($response['empresas'])) {
    echo "Empresas encontradas: " . count($response['empresas']) . "\n\n";
    foreach ($response['empresas'] as $empresa) {
        echo "Empresa: " . $empresa['nombre'] . "\n";
        echo "Logo URL: " . ($empresa['logo_url'] ?? 'null') . "\n\n";
    }
}

if (!empty($response['vehiculos_disponibles'])) {
    echo "=== URLs en vehículos disponibles ===\n\n";
    foreach ($response['vehiculos_disponibles'] as $vehiculo) {
        echo "Tipo: " . $vehiculo['tipo'] . "\n";
        foreach ($vehiculo['empresas'] as $emp) {
            echo "  - " . $emp['nombre'] . ": " . ($emp['logo_url'] ?? 'null') . "\n";
        }
    }
}
