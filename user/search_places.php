<?php
/**
 * Endpoint de búsqueda inteligente de lugares.
 *
 * Orden de salida:
 * 1) recientes
 * 2) Google Places
 *
 * Dedupe por place_id y límite total de 10.
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
    $query = trim((string)($_GET['query'] ?? ''));
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;

    $db = (new Database())->getConnection();
    // Compatibilidad: si no hay user_id, devolver solo resultados externos/cache.
    if ($userId <= 0) {
        $results = PlacesSearchService::searchWithRecent($db, 0, $query, $lat, $lng, 10);
    } else {
        $results = PlacesSearchService::searchWithRecent($db, $userId, $query, $lat, $lng, 10);
    }

    echo json_encode([
        'success' => true,
        'places' => $results,
        'total' => count($results),
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'places' => [],
    ]);
}
