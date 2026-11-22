<?php
/**
 * API: Obtener Empresas y Vehículos por Municipio
 * Endpoint: user/get_companies_by_municipality.php
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../middleware/SessionAccess.php';
require_once __DIR__ . '/../core/RateLimiter.php';
require_once __DIR__ . '/services/CompanyService.php';

if (Request::method() !== 'POST') {
    header('Allow: POST');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}

try {
    $user = SessionAccess::requireUser((new Database())->getConnection());
    if (!RateLimiter::check((int)$user['id'], 60, 60, 'company_quote')) {
        sendJsonResponse(false, 'Demasiadas consultas.', [], 429);
    }
    $data = getJsonInput();

    $latitud = filter_var($data['latitud'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitud = filter_var($data['longitud'] ?? null, FILTER_VALIDATE_FLOAT);
    $municipio = is_string($data['municipio'] ?? null) ? trim($data['municipio']) : '';
    $radioKm = filter_var($data['radio_km'] ?? 10, FILTER_VALIDATE_FLOAT);
    $distanciaKm = filter_var($data['distancia_km'] ?? 0, FILTER_VALIDATE_FLOAT);
    $duracionMinutos = filter_var($data['duracion_minutos'] ?? 0, FILTER_VALIDATE_INT);
    $search = is_string($data['search'] ?? null) ? trim($data['search']) : '';
    if ($latitud === false || abs($latitud) > 90 || $longitud === false || abs($longitud) > 180
        || $municipio === '' || mb_strlen($municipio) > 100 || mb_strlen($search) > 100
        || $radioKm === false || $radioKm < 1 || $radioKm > 60
        || $distanciaKm === false || $distanciaKm < 0 || $distanciaKm > 10000
        || $duracionMinutos === false || $duracionMinutos < 0 || $duracionMinutos > 10080) {
        sendJsonResponse(false, 'Datos de cotización inválidos.', [], 400);
    }

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

} catch (Throwable $error) {
    error_log('VIAX coverage: ' . get_class($error));
    sendJsonResponse(false, 'No pudimos consultar la disponibilidad.', [], 503);
}
