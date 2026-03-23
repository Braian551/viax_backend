<?php
/**
 * Endpoint para obtener búsquedas recientes del usuario.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

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

    echo json_encode([
        'success' => true,
        'recent_searches' => $items,
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
