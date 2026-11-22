<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';
require_once __DIR__ . '/../core/RateLimiter.php';

header('Content-Type: application/json; charset=UTF-8');
if (Request::method() !== 'POST') {
    header('Allow: POST');
    sendJsonResponse(false, 'Método no permitido.', [], 405);
}

try {
    $user = SessionAccess::requireUser((new Database())->getConnection());
    if (!RateLimiter::check((int)$user['id'], 60, 60, 'google_geo')
        || !RateLimiter::check('all', 600, 60, 'google_geo')) {
        sendJsonResponse(false, 'Demasiadas consultas de ubicación.', [], 429);
    }

    $data = getJsonInput();
    $operation = $data['operation'] ?? '';
    $params = $data['params'] ?? null;
    if (!is_string($operation) || !is_array($params)) {
        sendJsonResponse(false, 'Consulta de ubicación inválida.', [], 400);
    }

    $languages = ['es', 'en'];
    $lang = $params['languageCode'] ?? $params['language'] ?? 'es';
    if (!in_array($lang, $languages, true)) $lang = 'es';
    $session = $params['sessionToken'] ?? '';
    if (!is_string($session) || !preg_match('/^[A-Za-z0-9_-]{0,36}$/D', $session)) {
        sendJsonResponse(false, 'Identificador de búsqueda inválido.', [], 400);
    }
    $key = env_value('GOOGLE_PLACES_API_KEY');
    if (!is_string($key) || $key === '') {
        sendJsonResponse(false, 'El servicio de ubicación no está disponible.', [], 503);
    }

    $headers = ['Accept: application/json'];
    $body = null;
    switch ($operation) {
        case 'autocomplete':
            $input = $params['input'] ?? '';
            if (!is_string($input) || trim($input) === '' || mb_strlen($input) > 200) {
                sendJsonResponse(false, 'Texto de búsqueda inválido.', [], 400);
            }
            $url = 'https://places.googleapis.com/v1/places:autocomplete';
            $body = ['input' => $input, 'languageCode' => $lang];
            if ($session !== '') $body['sessionToken'] = $session;
            $region = $params['includedRegionCodes'][0] ?? '';
            if (is_string($region) && preg_match('/^[A-Z]{2}$/D', $region)) {
                $body['includedRegionCodes'] = [$region];
            }
            $circle = $params['locationBias']['circle'] ?? [];
            $lat = filter_var($circle['center']['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lng = filter_var($circle['center']['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($lat !== false && $lng !== false && abs($lat) <= 90 && abs($lng) <= 180) {
                $body['locationBias']['circle'] = [
                    'center' => ['latitude' => $lat, 'longitude' => $lng],
                    'radius' => max(1, min(50000, (float)($circle['radius'] ?? 50000))),
                ];
            }
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'X-Goog-Api-Key: ' . $key;
            $headers[] = 'X-Goog-FieldMask: suggestions.placePrediction.placeId,suggestions.placePrediction.text,suggestions.placePrediction.structuredFormat,suggestions.placePrediction.types,suggestions.placePrediction.distanceMeters';
            break;
        case 'details':
            $id = $params['place_id'] ?? '';
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $id)) {
                sendJsonResponse(false, 'Lugar inválido.', [], 400);
            }
            $query = ['languageCode' => $lang];
            if ($session !== '') $query['sessionToken'] = $session;
            $url = 'https://places.googleapis.com/v1/places/' . rawurlencode($id) . '?' . http_build_query($query);
            $headers[] = 'X-Goog-Api-Key: ' . $key;
            $headers[] = 'X-Goog-FieldMask: id,displayName,formattedAddress,location,types,addressComponents';
            break;
        case 'reverse':
        case 'nearby':
        case 'text':
        case 'landmark':
        case 'roads':
            $coordinates = $params['latlng'] ?? $params['location'] ?? $params['input'] ?? null;
            $path = $params['path'] ?? null;
            $validPair = static function ($pair): bool {
                if (!is_string($pair) || !preg_match('/^(-?[0-9]{1,3}(?:\.[0-9]{1,20})?),(-?[0-9]{1,3}(?:\.[0-9]{1,20})?)$/D', $pair, $m)) return false;
                return abs((float)$m[1]) <= 90 && abs((float)$m[2]) <= 180;
            };
            if ($operation === 'roads') {
                $points = is_string($path) && strlen($path) <= 260 ? explode('|', $path) : [];
                if (!$points || count($points) > 5 || array_filter($points, static fn ($p) => !$validPair($p))) {
                    sendJsonResponse(false, 'Coordenadas inválidas.', [], 400);
                }
                $url = 'https://roads.googleapis.com/v1/snapToRoads?' . http_build_query(['path' => $path, 'interpolate' => 'true', 'key' => $key]);
                break;
            }
            if (!$validPair($coordinates)) sendJsonResponse(false, 'Coordenadas inválidas.', [], 400);
            if ($operation === 'reverse') {
                $query = ['language' => $lang, 'key' => $key, 'latlng' => $coordinates];
                if (!empty($params['result_type'])) {
                    $query['result_type'] = 'locality|administrative_area_level_2';
                }
                $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query($query);
                break;
            }

            [$latitude, $longitude] = array_map('floatval', explode(',', $coordinates));
            $body = ['languageCode' => $lang];
            $circle = ['center' => compact('latitude', 'longitude'), 'radius' => 120];
            if ($operation === 'text') {
                $url = 'https://places.googleapis.com/v1/places:searchText';
                $input = $params['query'] ?? '';
                if (!is_string($input) || trim($input) === '' || mb_strlen($input) > 120) {
                    sendJsonResponse(false, 'Texto de búsqueda inválido.', [], 400);
                }
                $body += ['textQuery' => $input, 'locationBias' => ['circle' => $circle]];
            } else {
                $url = 'https://places.googleapis.com/v1/places:searchNearby';
                $body += ['locationRestriction' => ['circle' => $circle], 'rankPreference' => 'DISTANCE'];
            }
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'X-Goog-Api-Key: ' . $key;
            $headers[] = 'X-Goog-FieldMask: places.id,places.displayName,places.location,places.types';
            break;
        default:
            sendJsonResponse(false, 'Operación de ubicación inválida.', [], 400);
    }

    $request = curl_init($url);
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 6, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if ($body !== null) {
        curl_setopt($request, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($request);
    $http = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $response = is_string($raw) ? json_decode($raw, true) : null;
    if ($http !== 200 || !is_array($response)
        || !in_array($response['status'] ?? 'OK', ['OK', 'ZERO_RESULTS'], true)) {
        error_log('VIAX Google location upstream failed: ' . $operation . ' HTTP ' . $http);
        sendJsonResponse(false, 'No pudimos consultar Google Maps.', [], 502);
    }
    if (in_array($operation, ['nearby', 'landmark', 'text'], true)) {
        $places = array_map(static fn (array $place): array => [
            'place_id' => $place['id'] ?? null,
            'name' => $place['displayName']['text'] ?? '',
            'types' => $place['types'] ?? [],
            'geometry' => ['location' => [
                'lat' => $place['location']['latitude'] ?? null,
                'lng' => $place['location']['longitude'] ?? null,
            ]],
        ], $response['places'] ?? []);
        $resultKey = $operation === 'landmark' ? 'candidates' : 'results';
        $response = ['status' => $places ? 'OK' : 'ZERO_RESULTS', $resultKey => $places];
    }
    echo json_encode((object)$response, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('VIAX Google location: ' . get_class($error));
    sendJsonResponse(false, 'No pudimos consultar la ubicación.', [], 503);
}
