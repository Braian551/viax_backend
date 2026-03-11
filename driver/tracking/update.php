<?php
/**
 * API Push: actualizar tracking del conductor (modelo Uber/DiDi)
 * Endpoint: POST /driver/tracking/update
 *
 * Flujo fase 2:
 * 1) Validar punto GPS.
 * 2) Actualizar estado y métricas compactas en Redis.
 * 3) Publicar cambio a canal trip_updates:{trip_id}.
 * 4) Encolar punto en trip_tracking_queue para persistencia asíncrona.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/driver_service.php';

const TRACKING_STREAM_KEY = 'trip_tracking_stream';
const TRACKING_GROUP = 'tracking_workers';
const TRACKING_MAX_SPEED_KMH = 90.0;

function toFloat($value, float $default = 0.0): float {
    if ($value === null || $value === '') return $default;
    return floatval($value);
}

function toInt($value, int $default = 0): int {
    if ($value === null || $value === '') return $default;
    return intval($value);
}

function parseTimestampSec($raw): int {
    if (is_numeric($raw)) {
        $v = intval($raw);
        if ($v > 1000000000) {
            return $v;
        }
    }

    if (is_string($raw) && trim($raw) !== '') {
        $ts = strtotime($raw);
        if ($ts !== false) {
            return $ts;
        }
    }

    return time();
}

function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371000.0;

    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLon = deg2rad($lon2 - $lon1);

    $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
        cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) * sin($deltaLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

function decodeJsonSafe($raw): ?array {
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function resolveGoogleMapsApiKey(): string {
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

function snapToRoadIfPossible($redis, float $lat, float $lng): array {
    $key = 'gps_snap_cache:' . sha1(round($lat, 5) . ',' . round($lng, 5));
    if ($redis) {
        try {
            $cached = $redis->get($key);
            $decoded = decodeJsonSafe($cached);
            if (is_array($decoded) && isset($decoded['lat'], $decoded['lng'])) {
                return [floatval($decoded['lat']), floatval($decoded['lng']), 'cache'];
            }
        } catch (Throwable $e) {
            // Fallback silencioso.
        }
    }

    $apiKey = resolveGoogleMapsApiKey();
    if ($apiKey === '') {
        return [$lat, $lng, 'raw_no_key'];
    }

    $url = 'https://roads.googleapis.com/v1/snapToRoads?path=' .
        rawurlencode($lat . ',' . $lng) .
        '&interpolate=false&key=' . rawurlencode($apiKey);

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 1.2,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    $decoded = decodeJsonSafe($raw);
    $snapped = $decoded['snappedPoints'][0]['location'] ?? null;
    if (!is_array($snapped) || !isset($snapped['latitude'], $snapped['longitude'])) {
        return [$lat, $lng, 'raw_api_fail'];
    }

    $snapLat = floatval($snapped['latitude']);
    $snapLng = floatval($snapped['longitude']);

    if ($redis) {
        try {
            $redis->setex($key, 86400, json_encode([
                'lat' => $snapLat,
                'lng' => $snapLng,
                'source' => 'google_roads',
            ], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            // Cache secundaria.
        }
    }

    return [$snapLat, $snapLng, 'roads_api'];
}

function readMetrics($redis, string $metricsKey): array {
    if (!$redis) {
        return [];
    }

    try {
        $jsonRaw = $redis->get($metricsKey);
        $json = decodeJsonSafe($jsonRaw);
        if (is_array($json) && !empty($json)) {
            return $json;
        }

        // Compatibilidad con versión anterior (hash).
        $legacy = $redis->hGetAll($metricsKey);
        if (is_array($legacy) && !empty($legacy)) {
            return [
                'distance_km' => isset($legacy['distance_total_km']) ? floatval($legacy['distance_total_km']) : 0.0,
                'elapsed_time_sec' => isset($legacy['elapsed_time_sec']) ? intval($legacy['elapsed_time_sec']) : 0,
                'avg_speed_kmh' => isset($legacy['avg_speed_kmh']) ? floatval($legacy['avg_speed_kmh']) : 0.0,
                'last_timestamp' => isset($legacy['last_ts']) ? gmdate('c', intval($legacy['last_ts'])) : null,
                'last_ts' => isset($legacy['last_ts']) ? intval($legacy['last_ts']) : 0,
                'last_lat' => isset($legacy['last_lat']) ? floatval($legacy['last_lat']) : null,
                'last_lng' => isset($legacy['last_lng']) ? floatval($legacy['last_lng']) : null,
                'planned_route_km' => isset($legacy['planned_route_km']) ? floatval($legacy['planned_route_km']) : 0.0,
            ];
        }
    } catch (Throwable $e) {
        // Dejar flujo continuar sin bloquear tracking.
    }

    return [];
}

function bumpAnomalyCounters($redis, int $tripId, string $metric): void {
    if (!$redis) return;
    try {
        $redis->incr('metrics:anomaly:' . $metric);
        $redis->hIncrBy('trip:' . $tripId . ':anomalies', $metric, 1);
        $redis->expire('trip:' . $tripId . ':anomalies', 7200);
    } catch (Throwable $e) {
        // Observabilidad secundaria.
    }
}

function ensureTrackingConsumerGroup($redis): void {
    if (!$redis) {
        return;
    }

    try {
        $redis->rawCommand('XGROUP', 'CREATE', TRACKING_STREAM_KEY, TRACKING_GROUP, '0', 'MKSTREAM');
    } catch (Throwable $e) {
        // BUSYGROUP esperado cuando ya existe.
    }
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $tripId = toInt($input['trip_id'] ?? 0);
    $conductorId = toInt($input['conductor_id'] ?? 0);
    $lat = toFloat($input['lat'] ?? null, NAN);
    $lng = toFloat($input['lng'] ?? null, NAN);
    $timestampSec = parseTimestampSec($input['timestamp'] ?? null);
    $speedKmhInput = isset($input['speed']) ? max(0.0, toFloat($input['speed'])) : 0.0;
    $heading = isset($input['heading']) ? toFloat($input['heading']) : 0.0;
    $precisionGps = isset($input['precision_gps']) ? toFloat($input['precision_gps']) : null;

    if ($tripId <= 0 || $conductorId <= 0) {
        throw new Exception('trip_id y conductor_id son requeridos');
    }

    if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        throw new Exception('Coordenadas inválidas');
    }

    $redis = Cache::redis();
    if ($redis) {
        $redis->incr('metrics:tracking_updates');
    }

    [$lat, $lng, $snapSource] = snapToRoadIfPossible($redis, $lat, $lng);
    DriverGeoService::upsertDriverLocation($conductorId, $lat, $lng, $speedKmhInput);
    DriverGeoService::setDriverState($conductorId, 'on_trip');

    $now = time();
    $ttlSec = 7200;

    $metricsKey = 'trip:' . $tripId . ':metrics';
    $stateKey = 'trip:' . $tripId . ':state';

    $existing = readMetrics($redis, $metricsKey);
    $distanceTotalKm = isset($existing['distance_km']) ? floatval($existing['distance_km']) : 0.0;
    $elapsedSec = isset($existing['elapsed_time_sec']) ? intval($existing['elapsed_time_sec']) : 0;
    $lastLat = isset($existing['last_lat']) ? floatval($existing['last_lat']) : null;
    $lastLng = isset($existing['last_lng']) ? floatval($existing['last_lng']) : null;
    $lastTs = isset($existing['last_ts']) ? intval($existing['last_ts']) : 0;
    $plannedRouteKm = isset($existing['planned_route_km']) ? floatval($existing['planned_route_km']) : 0.0;

    $distanceMetersFromLast = 0.0;
    if ($lastLat !== null && $lastLng !== null) {
        $distanceMetersFromLast = haversineMeters($lastLat, $lastLng, $lat, $lng);
    }

    // Rate limiting fase 2:
    // permitir si delta>=1s O movimiento>10m.
    if ($lastTs > 0) {
        $rateDeltaSec = max(0, $timestampSec - $lastTs);
        if ($rateDeltaSec < 1 && $distanceMetersFromLast <= 10.0) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Rate limit: actualizaciones demasiado frecuentes sin movimiento relevante',
            ]);
            exit();
        }
    }

    if ($plannedRouteKm <= 0) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            $stmt = $db->prepare('SELECT distancia_estimada FROM solicitudes_servicio WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $tripId]);
            $plannedRouteKm = floatval($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $plannedRouteKm = 0.0;
        }
    }

    $deltaSec = 0;
    $distanceIncrementKm = 0.0;
    if ($lastLat !== null && $lastLng !== null && $lastTs > 0) {
        $deltaSec = max(0, $timestampSec - $lastTs);
        if ($deltaSec === 0) {
            bumpAnomalyCounters($redis, $tripId, 'invalid_timestamp');
        }

        $distanceIncrementKm = $distanceMetersFromLast / 1000.0;

        if ($deltaSec > 30) {
            $maxDistByLatency = TRACKING_MAX_SPEED_KMH * ($deltaSec / 3600.0);
            if ($distanceIncrementKm > $maxDistByLatency) {
                $distanceIncrementKm = $maxDistByLatency;
                bumpAnomalyCounters($redis, $tripId, 'distance_cap');
            }
        }

        // Anti-jitter: con baja velocidad reportada, pequeños saltos no deben sumar distancia.
        if ($speedKmhInput <= 3.0 && $distanceMetersFromLast <= 30.0) {
            $distanceIncrementKm = 0.0;
        }

        // Anti-drift agresivo en reposo: si el GPS reporta casi detenido,
        // saltos medianos/grandes en pocos segundos se consideran ruido.
        if ($speedKmhInput <= 1.0 && $deltaSec > 0 && $deltaSec <= 12 && $distanceMetersFromLast > 20.0) {
            $distanceIncrementKm = 0.0;
            bumpAnomalyCounters($redis, $tripId, 'gps_jump');
        }

        // Salto brusco en ventana corta: típico drift GPS/map-matching inestable.
        if ($deltaSec > 0 && $deltaSec <= 5 && $distanceMetersFromLast > 180.0) {
            $distanceIncrementKm = 0.0;
            bumpAnomalyCounters($redis, $tripId, 'gps_jump');
        }

        // Baja precisión + salto relevante: descartar para evitar inflado.
        if ($precisionGps !== null && $precisionGps > 45.0 && $distanceMetersFromLast > 50.0) {
            $distanceIncrementKm = 0.0;
            bumpAnomalyCounters($redis, $tripId, 'gps_jump');
        }

        if ($deltaSec > 0) {
            $speedKmhCalculated = ($distanceIncrementKm / $deltaSec) * 3600.0;
            if ($speedKmhCalculated > TRACKING_MAX_SPEED_KMH) {
                bumpAnomalyCounters($redis, $tripId, 'speed_overflow');
                $distanceIncrementKm = TRACKING_MAX_SPEED_KMH * ($deltaSec / 3600.0);
            }
        }

        if ($precisionGps !== null && $precisionGps > 120.0) {
            bumpAnomalyCounters($redis, $tripId, 'gps_jump');
            $distanceIncrementKm = 0.0;
        }

        if ($deltaSec > 0) {
            $maxDistanceKmForSegment = (TRACKING_MAX_SPEED_KMH * ($deltaSec / 3600.0));
            if ($distanceIncrementKm > $maxDistanceKmForSegment) {
                $distanceIncrementKm = $maxDistanceKmForSegment;
                bumpAnomalyCounters($redis, $tripId, 'distance_cap');
            }
        }

        $elapsedSec += $deltaSec;
        $distanceTotalKm += max(0.0, $distanceIncrementKm);
    }

    if ($plannedRouteKm > 0) {
        $maxByRoute = ($plannedRouteKm * 1.5);
        if ($distanceTotalKm > $maxByRoute) {
            $distanceTotalKm = $maxByRoute;
            bumpAnomalyCounters($redis, $tripId, 'distance_cap');
        }
    }

    $distanceTotalKm = round(max(0.0, $distanceTotalKm), 4);
    $avgSpeed = $elapsedSec > 0 ? round(($distanceTotalKm * 3600) / $elapsedSec, 2) : 0.0;
    $isoTs = gmdate('c', $timestampSec);

    $statePayload = [
        'lat' => $lat,
        'lng' => $lng,
        'timestamp' => $isoTs,
        'speed' => $speedKmhInput,
        'heading' => $heading,
    ];

    $metricsPayload = [
        'distance_km' => $distanceTotalKm,
        'elapsed_time_sec' => $elapsedSec,
        'avg_speed_kmh' => $avgSpeed,
        'price' => 0,
        'last_timestamp' => $isoTs,
        'last_ts' => $timestampSec,
        'last_lat' => $lat,
        'last_lng' => $lng,
        'planned_route_km' => round(max(0.0, $plannedRouteKm), 4),
    ];

    if ($redis) {
        ensureTrackingConsumerGroup($redis);

        $redis->setex($metricsKey, $ttlSec, json_encode($metricsPayload, JSON_UNESCAPED_UNICODE));
        Cache::set($stateKey, (string) json_encode($statePayload, JSON_UNESCAPED_UNICODE), $ttlSec);

        Cache::set('trip_tracking_latest:' . $tripId, (string) json_encode([
            'solicitud_id' => $tripId,
            'conductor_id' => $conductorId,
            'latitud' => $lat,
            'longitud' => $lng,
            'distancia_km' => $distanceTotalKm,
            'tiempo_seg' => $elapsedSec,
            'precio_parcial' => 0,
            'fase_viaje' => 'hacia_destino',
            'timestamp' => $timestampSec,
        ], JSON_UNESCAPED_UNICODE), $ttlSec);

        Cache::set('driver_location:' . $conductorId, (string) json_encode([
            'lat' => $lat,
            'lng' => $lng,
            'speed' => $speedKmhInput,
            'timestamp' => $timestampSec,
        ], JSON_UNESCAPED_UNICODE), 30);
        Cache::sAdd('active_drivers', (string) $conductorId);

        // Publicación compacta para SSE/passenger.
        $redis->publish('trip_updates:' . $tripId, json_encode([
            'trip_id' => $tripId,
            'tracking_actual' => [
                'ubicacion' => ['latitud' => $lat, 'longitud' => $lng],
                'distancia_km' => $distanceTotalKm,
                'tiempo_segundos' => $elapsedSec,
                'precio_actual' => 0,
                'velocidad_kmh' => $speedKmhInput,
                'heading_deg' => $heading,
                'fase' => 'hacia_destino',
                'ultima_actualizacion' => $isoTs,
            ],
        ], JSON_UNESCAPED_UNICODE));

        $redis->rawCommand(
            'XADD',
            TRACKING_STREAM_KEY,
            '*',
            'trip_id', (string)$tripId,
            'conductor_id', (string)$conductorId,
            'lat', (string)$lat,
            'lng', (string)$lng,
            'speed', (string)$speedKmhInput,
            'heading', (string)$heading,
            'timestamp', (string)$timestampSec,
            'precision_gps', (string)($precisionGps ?? 0),
            'distance_km', (string)$distanceTotalKm,
            'elapsed_time_sec', (string)$elapsedSec,
            'snap_source', $snapSource
        );

        $latencyMs = max(0, (int)(microtime(true) * 1000) - ($timestampSec * 1000));
        $redis->incrBy('metrics:tracking_latency', $latencyMs);
        $redis->incr('metrics:tracking_latency_count');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Tracking actualizado',
        'data' => [
            'trip_id' => $tripId,
            'distance_km' => $distanceTotalKm,
            'elapsed_time_sec' => $elapsedSec,
            'avg_speed_kmh' => $avgSpeed,
            'published' => $redis ? true : false,
            'queued' => $redis ? true : false,
            'stream' => TRACKING_STREAM_KEY,
            'snap_source' => $snapSource,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    error_log('driver/tracking/update.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
