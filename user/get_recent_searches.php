<?php
/**
 * Endpoint para obtener búsquedas recientes del usuario.
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../middleware/SessionAccess.php';
require_once __DIR__ . '/../services/places_search_service.php';

if (Request::method() !== 'GET') {
    header('Allow: GET');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}

try {
    $db = (new Database())->getConnection();
    $user = SessionAccess::requireUser($db);
    $userId = (int)$user['id'];
    if (isset($_GET['user_id']) && filter_var($_GET['user_id'], FILTER_VALIDATE_INT) !== $userId) {
        sendJsonResponse(false, 'Acceso denegado.', [], 403);
    }
    $items = PlacesSearchService::getRecentSearches($db, $userId, 10);
    $frequentPlaces = array_values(array_filter($items, static function ($item): bool {
        return is_array($item) && !empty($item['is_frequent_destination']);
    }));

    echo json_encode([
        'success' => true,
        'recent_searches' => $items,
        'frequent_places' => $frequentPlaces,
        'places' => $items,
        'total' => count($items),
    ]);
} catch (Throwable $error) {
    error_log('VIAX recent searches: ' . get_class($error));
    sendJsonResponse(false, 'No pudimos procesar las búsquedas recientes.', [], 503);
}
