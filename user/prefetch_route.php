<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/app.php';

function parsePoint(array $input, string $prefix = ''): array
{
    $latKey = $prefix !== '' ? $prefix . '_lat' : 'lat';
    $lngKey = $prefix !== '' ? $prefix . '_lng' : 'lng';

    $lat = isset($input[$latKey]) ? (float)$input[$latKey] : (float)($input['lat'] ?? 0);
    $lng = isset($input[$lngKey]) ? (float)$input[$lngKey] : (float)($input['lng'] ?? 0);

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        throw new Exception('Coordenadas inválidas');
    }

    return ['lat' => $lat, 'lng' => $lng];
}

function routeHash(float $lat, float $lng): string
{
    return number_format($lat, 4, '.', '') . '_' . number_format($lng, 4, '.', '');
}

function routeKey(array $origin, array $destination): string
{
    return 'route:' . routeHash($origin['lat'], $origin['lng']) . '|' . routeHash($destination['lat'], $destination['lng']);
}

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('JSON inválido');
    }

    $originRaw = $payload['origin'] ?? [];
    $destinationRaw = $payload['destination'] ?? [];

    $origin = parsePoint(is_array($originRaw) ? $originRaw : [], 'origin');
    $destination = parsePoint(is_array($destinationRaw) ? $destinationRaw : [], 'destination');

    $cacheKey = routeKey($origin, $destination);
    $redis = Cache::redis();
    if ($redis) {
        $cachedRaw = $redis->get($cacheKey);
        if (is_string($cachedRaw) && $cachedRaw !== '') {
            $cached = json_decode($cachedRaw, true);
            if (is_array($cached) && !empty($cached['polyline'])) {
                error_log('[RoutePreview] cache_hit key=' . $cacheKey);
                echo json_encode([
                    'success' => true,
                    'cache_hit' => true,
                    'route' => $cached,
                ]);
                exit();
            }
        }
    }

    error_log('[RoutePreview] cache_miss key=' . $cacheKey);

    $token = trim((string)($payload['mapbox_token'] ?? ''));
    if ($token === '') {
        $token = getenv('MAPBOX_ACCESS_TOKEN') ?: getenv('MAPBOX_TOKEN') ?: '';
    }
    if ($token === '') {
        throw new Exception('MAPBOX token no configurado en backend');
    }

    $start = microtime(true);
    $coords = $origin['lng'] . ',' . $origin['lat'] . ';' . $destination['lng'] . ',' . $destination['lat'];
    $url = 'https://api.mapbox.com/directions/v5/mapbox/driving/' . $coords
        . '?alternatives=false&steps=false&geometries=polyline&overview=simplified&access_token=' . urlencode($token);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
    ]);
    $responseBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false || $httpCode !== 200) {
        throw new Exception('Mapbox directions error: ' . ($curlError ?: ('HTTP ' . $httpCode)));
    }

    $mapboxData = json_decode($responseBody, true);
    $routes = $mapboxData['routes'] ?? [];
    if (!is_array($routes) || empty($routes)) {
        throw new Exception('No se encontró ruta');
    }

    $route = $routes[0];
    $polyline = (string)($route['geometry'] ?? '');
    if ($polyline === '') {
        throw new Exception('Ruta sin geometría');
    }

    $durationMs = (int)round((microtime(true) - $start) * 1000);
    $routePayload = [
        'distance_meters' => (float)($route['distance'] ?? 0),
        'duration_seconds' => (float)($route['duration'] ?? 0),
        'polyline' => $polyline,
        'created_at' => gmdate('c'),
    ];

    if ($redis) {
        $redis->setex($cacheKey, 300, json_encode($routePayload, JSON_UNESCAPED_UNICODE));
    }

    error_log('[RoutePreview] prefetch_complete key=' . $cacheKey . ' duration_ms=' . $durationMs);

    echo json_encode([
        'success' => true,
        'cache_hit' => false,
        'route' => $routePayload,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
