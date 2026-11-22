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
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../middleware/SessionAccess.php';
require_once __DIR__ . '/../services/places_search_service.php';

if (Request::method() !== 'POST') {
    header('Allow: POST');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}

try {
    $db = (new Database())->getConnection();
    $user = SessionAccess::requireUser($db);
    $userId = (int)$user['id'];
    $input = getJsonInput();
    if (isset($input['user_id']) && filter_var($input['user_id'], FILTER_VALIDATE_INT) !== $userId) {
        sendJsonResponse(false, 'Acceso denegado.', [], 403);
    }
    $name = $input['place_name'] ?? $input['name'] ?? null;
    $address = $input['place_address'] ?? $input['address'] ?? null;
    $lat = filter_var($input['place_lat'] ?? $input['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($input['place_lng'] ?? $input['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $placeId = $input['place_id'] ?? null;
    if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 200
        || !is_string($address) || trim($address) === '' || mb_strlen($address) > 500
        || $lat === false || abs($lat) > 90 || $lng === false || abs($lng) > 180
        || ($placeId !== null && (!is_string($placeId) || strlen($placeId) > 200))) {
        sendJsonResponse(false, 'Datos de búsqueda inválidos.', [], 400);
    }
    PlacesSearchService::saveRecentSearch($db, $userId, $name, $address, $lat, $lng, $placeId);

    echo json_encode([
        'success' => true,
        'message' => 'Búsqueda guardada',
    ]);
} catch (Throwable $error) {
    error_log('VIAX recent searches: ' . get_class($error));
    sendJsonResponse(false, 'No pudimos procesar las búsquedas recientes.', [], 503);
}
