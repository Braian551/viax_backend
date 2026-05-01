<?php
/**
 * Endpoint para guardar una búsqueda reciente del usuario.
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/places_search_service.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $name = trim((string)($input['place_name'] ?? $input['name'] ?? ''));
    $address = trim((string)($input['place_address'] ?? $input['address'] ?? ''));
    $lat = isset($input['place_lat']) ? (float)$input['place_lat'] : (isset($input['lat']) ? (float)$input['lat'] : 0.0);
    $lng = isset($input['place_lng']) ? (float)$input['place_lng'] : (isset($input['lng']) ? (float)$input['lng'] : 0.0);
    $placeId = isset($input['place_id']) ? trim((string)$input['place_id']) : null;

    if ($userId <= 0) {
        throw new Exception('user_id es requerido');
    }
    if ($name === '' || $address === '') {
        throw new Exception('place_name y place_address son requeridos');
    }

    $db = (new Database())->getConnection();
    PlacesSearchService::saveRecentSearch($db, $userId, $name, $address, $lat, $lng, $placeId);

    echo json_encode([
        'success' => true,
        'message' => 'Búsqueda guardada',
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
