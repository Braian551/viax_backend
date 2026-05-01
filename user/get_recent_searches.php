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
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/places_search_service.php';

try {
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if ($userId <= 0) {
        throw new Exception('user_id es requerido');
    }

    $db = (new Database())->getConnection();
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
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'recent_searches' => [],
    ]);
}
