<?php
/**
 * Servicio de deteccion de trafico usando Google Routes API.
 *
 * Objetivo:
 * - Detectar congestion real entre dos puntos.
 * - Reutilizar resultados por par de zonas para bajar costos.
 * - Evitar llamadas innecesarias (cache, lock, viajes cortos).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/traffic_zone_resolver.php';
require_once __DIR__ . '/traffic_cache.php';

function trafficHaversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

/**
 * Convierte formato de duracion de Routes (ej: "821s") a segundos.
 */
function trafficParseDurationSeconds($durationRaw): int
{
    if (is_numeric($durationRaw)) {
        return max(0, intval(round(floatval($durationRaw))));
    }

    if (!is_string($durationRaw) || trim($durationRaw) === '') {
        return 0;
    }

    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', trim($durationRaw), $m)) {
        return max(0, intval(round(floatval($m[1]))));
    }

    return 0;
}

function trafficClassifyLevel(float $ratio): string
{
    if ($ratio < 1.2) {
        return 'normal';
    }
    if ($ratio <= 1.5) {
        return 'moderado';
    }
    return 'alto';
}

/**
 * Estructura normalizada para respuesta/fallback.
 */
function trafficBuildDefaultResult(string $source, string $reason = ''): array
{
    return [
        'distance_meters' => 0,
        'duration_seconds' => 0,
        'static_duration_seconds' => 0,
        'traffic_ratio' => 1.0,
        'traffic_level' => 'normal',
        'peak_traffic' => false,
        'source' => $source,
        'reason' => $reason,
        'api_called' => false,
        'timestamp' => gmdate('c'),
    ];
}

/**
 * Resuelve API key de Google para Routes.
 *
 * Orden de prioridad:
 * 1) GOOGLE_MAPS_API_KEY
 * 2) GOOGLE_PLACES_API_KEY
 * 3) GOOGLE_API_KEY
 */
function trafficResolveGoogleApiKey(): string
{
    $candidates = [
        'GOOGLE_MAPS_API_KEY',
        'GOOGLE_PLACES_API_KEY',
        'GOOGLE_API_KEY',
    ];

    foreach ($candidates as $name) {
        $value = trim((string) env_value($name, ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * Calcula trafico con cache por zonas y throttling.
 *
 * Uso:
 * $traffic = trafficGetConditions($oLat, $oLng, $dLat, $dLng);
 */
function trafficGetConditions(float $originLat, float $originLng, float $destLat, float $destLng): array
{
    if ($originLat < -90 || $originLat > 90 || $destLat < -90 || $destLat > 90
        || $originLng < -180 || $originLng > 180 || $destLng < -180 || $destLng > 180) {
        return trafficBuildDefaultResult('invalid_coords', 'coordenadas_invalidas');
    }

    $distanceApprox = trafficHaversineMeters($originLat, $originLng, $destLat, $destLng);
    if ($distanceApprox < 500) {
        $res = trafficBuildDefaultResult('short_trip_skip', 'distancia_menor_500m');
        $res['distance_meters'] = intval(round($distanceApprox));
        return $res;
    }

    $zone = trafficResolveZonePair($originLat, $originLng, $destLat, $destLng);
    $zonePairKey = $zone['zone_pair_key'];

    $cached = trafficCacheGetFresh($zonePairKey);
    if (is_array($cached)) {
        $cached['source'] = 'cache_fresh';
        $cached['api_called'] = false;
        return $cached;
    }

    $apiKey = trafficResolveGoogleApiKey();
    if ($apiKey === '') {
        $historical = trafficCacheGetHistorical($zonePairKey);
        if (is_array($historical)) {
            $historical['source'] = 'cache_historical_no_key';
            $historical['api_called'] = false;
            return $historical;
        }
        return trafficBuildDefaultResult('no_api_key', 'GOOGLE_MAPS_API_KEY_o_GOOGLE_PLACES_API_KEY_no_configurada');
    }

    if (!trafficCacheTryAcquireLock($zonePairKey, 15)) {
        $historical = trafficCacheGetHistorical($zonePairKey);
        if (is_array($historical)) {
            $historical['source'] = 'cache_historical_lock';
            $historical['api_called'] = false;
            return $historical;
        }
        return trafficBuildDefaultResult('throttled_lock', 'lock_activo_para_zona');
    }

    $payload = [
        'origin' => [
            'location' => [
                'latLng' => [
                    'latitude' => $originLat,
                    'longitude' => $originLng,
                ],
            ],
        ],
        'destination' => [
            'location' => [
                'latLng' => [
                    'latitude' => $destLat,
                    'longitude' => $destLng,
                ],
            ],
        ],
        'travelMode' => 'DRIVE',
        'routingPreference' => 'TRAFFIC_AWARE',
    ];

    $url = 'https://routes.googleapis.com/directions/v2:computeRoutes';
    $attempts = 3;
    $responseData = null;

    for ($i = 0; $i < $attempts; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $apiKey,
                'X-Goog-FieldMask: routes.duration,routes.staticDuration,routes.distanceMeters',
            ],
        ]);

        $raw = curl_exec($ch);
        $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw !== false && $status >= 200 && $status < 300) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $responseData = $decoded;
                break;
            }
        }

        error_log('[traffic_service] intento ' . ($i + 1) . ' fallo status=' . $status . ' error=' . $error);

        // Backoff exponencial corto: 150ms, 300ms, 600ms.
        usleep(150000 * (2 ** $i));
    }

    if (!is_array($responseData) || empty($responseData['routes'][0])) {
        $historical = trafficCacheGetHistorical($zonePairKey);
        if (is_array($historical)) {
            $historical['source'] = 'cache_historical_after_error';
            $historical['api_called'] = false;
            return $historical;
        }
        return trafficBuildDefaultResult('api_error', 'routes_sin_respuesta_valida');
    }

    $route = $responseData['routes'][0];
    $durationSec = trafficParseDurationSeconds($route['duration'] ?? null);
    $staticDurationSec = trafficParseDurationSeconds($route['staticDuration'] ?? null);
    $distanceMeters = intval($route['distanceMeters'] ?? 0);

    if ($distanceMeters <= 0) {
        $distanceMeters = intval(round($distanceApprox));
    }

    if ($durationSec <= 0) {
        $historical = trafficCacheGetHistorical($zonePairKey);
        if (is_array($historical)) {
            $historical['source'] = 'cache_historical_invalid_duration';
            $historical['api_called'] = false;
            return $historical;
        }
        return trafficBuildDefaultResult('invalid_response', 'duration_invalida');
    }

    if ($staticDurationSec <= 0) {
        $staticDurationSec = $durationSec;
    }

    $ratio = $staticDurationSec > 0
        ? max(0.1, $durationSec / $staticDurationSec)
        : 1.0;

    $result = [
        'origin_zone_key' => $zone['origin_zone_key'],
        'destination_zone_key' => $zone['destination_zone_key'],
        'distance_meters' => $distanceMeters,
        'duration_seconds' => $durationSec,
        'static_duration_seconds' => $staticDurationSec,
        'traffic_ratio' => round($ratio, 4),
        'traffic_level' => trafficClassifyLevel($ratio),
        'peak_traffic' => $ratio > 1.5,
        'source' => 'google_routes',
        'reason' => '',
        'api_called' => true,
        'timestamp' => gmdate('c'),
    ];

    trafficCacheStore($zonePairKey, $result);

    return $result;
}
