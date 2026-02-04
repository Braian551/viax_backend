<?php
/**
 * API: Obtener Empresas y Vehículos por Municipio
 * Endpoint: user/get_companies_by_municipality.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/services/CompanyService.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    if (!isset($data['latitud']) || !isset($data['longitud']) || !isset($data['municipio'])) {
        throw new Exception('Datos requeridos: latitud, longitud, municipio');
    }
    
    $latitud = floatval($data['latitud']);
    $longitud = floatval($data['longitud']);
    $municipio = trim($data['municipio']);
    $radioKm = floatval($data['radio_km'] ?? 10.0);
    $distanciaKm = floatval($data['distancia_km'] ?? 0);
    $duracionMinutos = intval($data['duracion_minutos'] ?? 0);
    $search = trim($data['search'] ?? '');
    
    $service = new CompanyService();
    $response = $service->getCompaniesByMunicipality(
        $municipio, 
        $latitud, 
        $longitud, 
        $distanciaKm, 
        $duracionMinutos, 
        $radioKm,
        $search
    );
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
