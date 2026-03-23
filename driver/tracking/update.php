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
require_once __DIR__ . '/../../services/roads_snap_service.php';
require_once __DIR__ . '/../../conductor/driver_auth.php';

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

function normalizeTrackingVehicleType($tipoVehiculo): string {
    $normalized = strtolower(trim((string)$tipoVehiculo));
    $aliases = [
        'moto_taxi' => 'mototaxi',
        'moto taxi' => 'mototaxi',
        'mototaxi' => 'mototaxi',
        'motocarro' => 'mototaxi',
        'moto_carga' => 'mototaxi',
        'motorcycle' => 'moto',
        'auto' => 'carro',
        'automovil' => 'carro',
        'car' => 'carro',
    ];

    if (isset($aliases[$normalized])) {
        return $aliases[$normalized];
    }

    return $normalized !== '' ? $normalized : 'moto';
}

function trackingPricingFallbackConfig(): array {
    return [
        'tarifa_base' => 0.0,
        'costo_por_km' => 0.0,
        'costo_por_minuto' => 0.0,
        'tarifa_minima' => 0.0,
        'tarifa_maxima' => null,
        'umbral_km_descuento' => 15.0,
        'descuento_distancia_larga' => 0.0,
    ];
}

function fetchTrackingPricingConfig(PDO $db, ?int $empresaId, ?string $tipoVehiculoRaw): array {
    $tipo = normalizeTrackingVehicleType($tipoVehiculoRaw ?? 'moto');
    $tiposCandidatos = array_values(array_unique([$tipo, 'moto']));

    foreach ($tiposCandidatos as $tipoCandidato) {
        if (!empty($empresaId) && $empresaId > 0) {
            $stmt = $db->prepare("\n                SELECT
                    tarifa_base,
                    costo_por_km,
                    costo_por_minuto,
                    tarifa_minima,
                    tarifa_maxima,
                    umbral_km_descuento,
                    descuento_distancia_larga
                FROM configuracion_precios
                WHERE empresa_id = :empresa_id
                  AND tipo_vehiculo = :tipo
                  AND activo = 1
                LIMIT 1
            ");
            $stmt->execute([
                ':empresa_id' => (int)$empresaId,
                ':tipo' => $tipoCandidato,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                return $row;
            }
        }

        $stmt = $db->prepare("\n            SELECT
                tarifa_base,
                costo_por_km,
                costo_por_minuto,
                tarifa_minima,
                tarifa_maxima,
                umbral_km_descuento,
                descuento_distancia_larga
            FROM configuracion_precios
            WHERE empresa_id IS NULL
              AND tipo_vehiculo = :tipo
              AND activo = 1
            LIMIT 1
        ");
        $stmt->execute([':tipo' => $tipoCandidato]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row)) {
            return $row;
        }
    }

    return trackingPricingFallbackConfig();
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

function upsertDriverLiveLocationSnapshot(int $conductorId, float $lat, float $lng, ?float $speedKmh, string $source = 'tracking'): void
{
    $throttleKey = 'driver_live_location:last_db:' . $conductorId;
    $lastPersist = intval(Cache::get($throttleKey) ?? 0);
    $now = time();
    if ($lastPersist > 0 && ($now - $lastPersist) < 5) {
        return;
    }

    try {
        $gridId = DriverGeoService::gridIdForCoordinates($lat, $lng);
        $cityId = DriverGeoService::getCityIdFromCoordinates($lat, $lng);

        $database = new Database();
        $db = $database->getConnection();
        $stmt = $db->prepare("\n            INSERT INTO drivers_live_location (\n                conductor_id, lat, lng, speed_kmh, grid_id, city_id, source, updated_at\n            ) VALUES (\n                :conductor_id, :lat, :lng, :speed_kmh, :grid_id, :city_id, :source, NOW()\n            )\n            ON CONFLICT (conductor_id) DO UPDATE SET\n                lat = EXCLUDED.lat,\n                lng = EXCLUDED.lng,\n                speed_kmh = EXCLUDED.speed_kmh,\n                grid_id = EXCLUDED.grid_id,\n                city_id = EXCLUDED.city_id,\n                source = EXCLUDED.source,\n                updated_at = NOW()\n        ");
        $stmt->execute([
            ':conductor_id' => $conductorId,
            ':lat' => $lat,
            ':lng' => $lng,
            ':speed_kmh' => $speedKmh,
            ':grid_id' => $gridId,
            ':city_id' => $cityId,
            ':source' => $source,
        ]);

        Cache::set($throttleKey, (string)$now, 10);
    } catch (Throwable $e) {
        error_log('drivers_live_location upsert warning: ' . $e->getMessage());
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

    // Validación de sesión para bloquear tracking de conductores fantasma.
    $sessionToken = driverSessionTokenFromRequest($input);
    $session = validateDriverSession($conductorId, $sessionToken, false);
    if (!$session['ok']) {
        throw new Exception($session['message']);
    }
    DriverGeoService::touchDriverHeartbeat($conductorId, 20);

    if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        throw new Exception('Coordenadas inválidas');
    }

    $redis = Cache::redis();
    if ($redis) {
        $redis->incr('metrics:tracking_updates');
    }

    [$lat, $lng, $snapSource] = RoadsSnapService::snapDriverPoint($redis, $tripId, $conductorId, $lat, $lng);
    DriverGeoService::upsertDriverLocation($conductorId, $lat, $lng, $speedKmhInput);
    DriverGeoService::setDriverState($conductorId, 'on_trip');
    upsertDriverLiveLocationSnapshot($conductorId, $lat, $lng, $speedKmhInput, 'tracking');

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

    $estimatedTimeMin = isset($existing['estimated_time_min']) ? intval($existing['estimated_time_min']) : 0;
    $estimatedPrice = isset($existing['estimated_price']) ? floatval($existing['estimated_price']) : 0.0;
    $empresaId = null;
    $tipoVehiculo = 'moto';
    $db = null;

    try {
        $database = new Database();
        $db = $database->getConnection();
        $stmt = $db->prepare('SELECT distancia_estimada, tiempo_estimado, precio_estimado, empresa_id, tipo_vehiculo FROM solicitudes_servicio WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $tripId]);
        $tripRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($tripRow)) {
            if ($plannedRouteKm <= 0) {
                $plannedRouteKm = floatval($tripRow['distancia_estimada'] ?? 0);
            }
            if ($estimatedTimeMin <= 0) {
                $estimatedTimeMin = intval($tripRow['tiempo_estimado'] ?? 0);
            }
            if ($estimatedPrice <= 0) {
                $estimatedPrice = floatval($tripRow['precio_estimado'] ?? 0);
            }
            if (isset($tripRow['empresa_id'])) {
                $empresaId = intval($tripRow['empresa_id']);
            }
            if (!empty($tripRow['tipo_vehiculo'])) {
                $tipoVehiculo = (string)$tripRow['tipo_vehiculo'];
            }
        }
    } catch (Throwable $e) {
        $plannedRouteKm = max(0.0, $plannedRouteKm);
        $db = null;
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

    // Progreso del viaje (solo para suavizar ajuste de tarifa mínima en vivo).
    $distanceRatio = $plannedRouteKm > 0.0 ? ($distanceTotalKm / max(0.1, $plannedRouteKm)) : 0.0;
    $timeRatio = $estimatedTimeMin > 0 ? ($elapsedSec / max(60.0, $estimatedTimeMin * 60.0)) : 0.0;
    $progress = max($distanceRatio, $timeRatio);
    $progress = min(1.0, max(0.0, $progress));

    $currentPrice = 0.0;
    if ($db instanceof PDO) {
        try {
            $pricingConfig = fetchTrackingPricingConfig(
                $db,
                $empresaId !== null ? intval($empresaId) : null,
                $tipoVehiculo
            );

            $tarifaBase = max(0.0, floatval($pricingConfig['tarifa_base'] ?? 0));
            $costoPorKm = max(0.0, floatval($pricingConfig['costo_por_km'] ?? 0));
            $costoPorMinuto = max(0.0, floatval($pricingConfig['costo_por_minuto'] ?? 0));
            $tarifaMinima = max(0.0, floatval($pricingConfig['tarifa_minima'] ?? 0));
            $tarifaMaxima = isset($pricingConfig['tarifa_maxima']) ? floatval($pricingConfig['tarifa_maxima']) : 0.0;
            $umbralDescuentoKm = max(0.0, floatval($pricingConfig['umbral_km_descuento'] ?? 0));
            $descuentoDistanciaLargaPct = max(0.0, floatval($pricingConfig['descuento_distancia_larga'] ?? 0));

            $precioDistancia = $distanceTotalKm * $costoPorKm;
            $precioTiempo = ($elapsedSec / 60.0) * $costoPorMinuto;
            $subtotal = $tarifaBase + $precioDistancia + $precioTiempo;

            if ($umbralDescuentoKm > 0.0 && $distanceTotalKm >= $umbralDescuentoKm && $descuentoDistanciaLargaPct > 0.0) {
                $subtotal -= ($subtotal * ($descuentoDistanciaLargaPct / 100.0));
            }

            $runningPrice = max(0.0, $subtotal);

            // Tarifa mínima progresiva: evita salto brusco al final y conserva sensación de taxímetro.
            if ($tarifaMinima > 0.0 && $runningPrice < $tarifaMinima) {
                $minFloor = $tarifaBase + (($tarifaMinima - $tarifaBase) * $progress);
                $runningPrice = max($runningPrice, $minFloor);
            }

            if ($tarifaMaxima > 0.0) {
                $runningPrice = min($runningPrice, $tarifaMaxima);
            }

            $currentPrice = round($runningPrice, 2);
        } catch (Throwable $e) {
            // Fallback legacy si falla lectura de configuración.
            if ($estimatedPrice > 0.0) {
                $currentPrice = round($estimatedPrice * $progress, 2);
            }
        }
    } elseif ($estimatedPrice > 0.0) {
        $currentPrice = round($estimatedPrice * $progress, 2);
    }

    if ($db instanceof PDO) {
        try {
            $stmtPersist = $db->prepare("\n                UPDATE solicitudes_servicio
                SET
                    precio_en_tracking = :precio_en_tracking,
                    distancia_recorrida = :distancia_recorrida,
                    tiempo_transcurrido = :tiempo_transcurrido
                WHERE id = :trip_id
            ");
            $stmtPersist->execute([
                ':precio_en_tracking' => $currentPrice,
                ':distancia_recorrida' => $distanceTotalKm,
                ':tiempo_transcurrido' => $elapsedSec,
                ':trip_id' => $tripId,
            ]);
        } catch (Throwable $e) {
            // No bloquear tracking en vivo por error de persistencia secundaria.
        }
    }

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
        'price' => $currentPrice,
        'last_timestamp' => $isoTs,
        'last_ts' => $timestampSec,
        'last_lat' => $lat,
        'last_lng' => $lng,
        'planned_route_km' => round(max(0.0, $plannedRouteKm), 4),
        'estimated_time_min' => max(0, $estimatedTimeMin),
        'estimated_price' => max(0.0, $estimatedPrice),
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
            'precio_parcial' => $currentPrice,
            'fase_viaje' => 'hacia_destino',
            'timestamp' => $timestampSec,
        ], JSON_UNESCAPED_UNICODE), $ttlSec);

        Cache::set('driver_location:' . $conductorId, (string) json_encode([
            'lat' => $lat,
            'lng' => $lng,
            'speed' => $speedKmhInput,
            'timestamp' => $timestampSec,
        ], JSON_UNESCAPED_UNICODE), 30);
        $redis->setex('drivers:location:' . $conductorId, 30, json_encode([
            'lat' => $lat,
            'lng' => $lng,
            'bearing' => $heading,
            'speed' => $speedKmhInput,
            'timestamp' => $timestampSec,
        ], JSON_UNESCAPED_UNICODE));
        Cache::sAdd('active_drivers', (string) $conductorId);

        // Publicación compacta para SSE/passenger.
        $redis->publish('trip_updates:' . $tripId, json_encode([
            'trip_id' => $tripId,
            'tracking_actual' => [
                'ubicacion' => ['latitud' => $lat, 'longitud' => $lng],
                'distancia_km' => $distanceTotalKm,
                'tiempo_segundos' => $elapsedSec,
                'precio_actual' => $currentPrice,
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
            'precio_parcial' => $currentPrice,
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
