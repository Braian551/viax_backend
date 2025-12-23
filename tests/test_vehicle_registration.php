<?php
/**
 * Test script for vehicle registration endpoints
 */

echo "=== Testing Vehicle Registration Endpoints ===\n\n";

// Test data
$conductorId = 7; // Replace with a valid conductor ID from your database

// Test 1: Update License
echo "Test 1: Updating license...\n";
$licenseData = json_encode([
    'conductor_id' => $conductorId,
    'licencia_conduccion' => '123456789',
    'licencia_expedicion' => '2023-01-15',
    'licencia_vencimiento' => '2028-01-15',
    'licencia_categoria' => 'C1'
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/pingo/backend/conductor/update_license.php');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $licenseData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response Code: $httpCode\n";
echo "Response: $response\n\n";

$licenseResult = json_decode($response, true);
if ($licenseResult && $licenseResult['success']) {
    echo "✓ License updated successfully\n\n";
} else {
    echo "✗ Failed to update license\n\n";
}

// Test 2: Update Vehicle
echo "Test 2: Updating vehicle...\n";
$vehicleData = json_encode([
    'conductor_id' => $conductorId,
    'vehiculo_tipo' => 'moto',
    'vehiculo_marca' => 'Honda',
    'vehiculo_modelo' => 'CB300R',
    'vehiculo_anio' => 2023,
    'vehiculo_color' => 'Rojo',
    'vehiculo_placa' => 'ABC123',
    'soat_numero' => 'SOAT123456',
    'soat_vencimiento' => '2025-12-31',
    'tecnomecanica_numero' => 'TEC789012',
    'tecnomecanica_vencimiento' => '2025-12-31',
    'tarjeta_propiedad_numero' => 'TP345678'
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/pingo/backend/conductor/update_vehicle.php');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $vehicleData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response Code: $httpCode\n";
echo "Response: $response\n\n";

$vehicleResult = json_decode($response, true);
if ($vehicleResult && $vehicleResult['success']) {
    echo "✓ Vehicle updated successfully\n\n";
} else {
    echo "✗ Failed to update vehicle\n\n";
}

// Test 3: Get Profile to verify data
echo "Test 3: Getting profile to verify...\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost/pingo/backend/conductor/get_profile.php?conductor_id=$conductorId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response Code: $httpCode\n";
$profileResult = json_decode($response, true);

if ($profileResult && $profileResult['success'] && isset($profileResult['profile'])) {
    echo "✓ Profile retrieved successfully\n";
    echo "\nProfile data:\n";
    echo "  License: " . ($profileResult['profile']['licencia_conduccion'] ?? 'N/A') . "\n";
    echo "  Category: " . ($profileResult['profile']['licencia_categoria'] ?? 'N/A') . "\n";
    echo "  Vehicle: " . ($profileResult['profile']['vehiculo_placa'] ?? 'N/A') . "\n";
    echo "  SOAT: " . ($profileResult['profile']['soat_numero'] ?? 'N/A') . "\n";
    echo "  Tecnomecanica: " . ($profileResult['profile']['tecnomecanica_numero'] ?? 'N/A') . "\n";
    echo "  Tarjeta Propiedad: " . ($profileResult['profile']['tarjeta_propiedad_numero'] ?? 'N/A') . "\n";
} else {
    echo "✗ Failed to retrieve profile\n";
}

echo "\n=== Testing Complete ===\n";
?>
