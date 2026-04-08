<?php
/**
 * API de compatibilidad para previsualización de viaje.
 *
 * Este endpoint mantiene contrato estable y reutiliza CompanyService.
 * Campos aditivos incluidos:
 * - pickup_eta_minutes
 * - surge_multiplier
 * - driver_distance
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/services/CompanyService.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('JSON inválido');
    }

    if (!isset($data['latitud']) || !isset($data['longitud']) || !isset($data['municipio'])) {
        throw new Exception('Datos requeridos: latitud, longitud, municipio');
    }

    $latitud = (float)$data['latitud'];
    $longitud = (float)$data['longitud'];
    $municipio = trim((string)$data['municipio']);
    $radioKm = (float)($data['radio_km'] ?? 10.0);
    $distanciaKm = (float)($data['distancia_km'] ?? 0.0);
    $duracionMinutos = (int)($data['duracion_minutos'] ?? 0);
    $search = trim((string)($data['search'] ?? ''));

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

    // Compatibilidad aditiva: normalizar llaves esperadas por clientes nuevos.
    if (!isset($response['pickup_eta_minutes'])) {
        $response['pickup_eta_minutes'] = null;
    }
    if (!isset($response['surge_multiplier'])) {
        $response['surge_multiplier'] = 1.0;
    }
    if (!isset($response['driver_distance'])) {
        $response['driver_distance'] = null;
    }

    echo json_encode($response);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
