<?php
/**
 * Worker de reposicionamiento de conductores.
 *
 * Objetivo:
 * - Leer hotspots activos.
 * - Detectar conductores inactivos/disponibles.
 * - Emitir sugerencia de movimiento para mejorar cobertura.
 *
 * Frecuencia: 60 segundos.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/driver_service.php';

const REPOSITION_LOOP_SEC = 60;
const REPOSITION_TOP_HOTSPOTS = 10;
const REPOSITION_MAX_SUGGESTIONS = 50;
const REPOSITION_MAX_DISTANCE_KM = 8.0;

/**
 * @return list<array{zone:string,score:float,grid_id:string,lat:float,lng:float}>
 */
function getTopHotspots($redis): array
{
    $rows = $redis->zRevRange('dispatch:hotspots:zset', 0, REPOSITION_TOP_HOTSPOTS - 1, true);
    if (!is_array($rows) || empty($rows)) {
        return [];
    }

    $out = [];
    foreach ($rows as $zoneKey => $scoreRaw) {
        $zone = (string)$zoneKey;
        if (strpos($zone, 'zone:') !== 0) {
            continue;
        }

        $gridId = substr($zone, strlen('zone:'));
        if ($gridId === '') {
            continue;
        }

        [$lat, $lng] = DriverGeoService::gridCenterFromId($gridId);
        if ($lat === 0.0 && $lng === 0.0) {
            continue;
        }

        $out[] = [
            'zone' => $zone,
            'score' => (float)$scoreRaw,
            'grid_id' => $gridId,
            'lat' => $lat,
            'lng' => $lng,
        ];
    }

    return $out;
}

/**
 * @return list<int>
 */
function getIdleDrivers($redis): array
{
    $members = $redis->sMembers('drivers:idle');
    if (!is_array($members) || empty($members)) {
        return [];
    }

    $out = [];
    foreach ($members as $member) {
        $driverId = (int)$member;
        if ($driverId <= 0) {
            continue;
        }

        if (!$redis->sIsMember('drivers:available', (string)$driverId)) {
            continue;
        }

        if (!$redis->exists('driver:heartbeat:' . $driverId)) {
            DriverGeoService::removeDriverFromRealtimeIndexes($driverId);
            continue;
        }

        if ($redis->exists('driver_offer_lock:' . $driverId) || $redis->exists('driver:cooldown:' . $driverId)) {
            continue;
        }

        $out[] = $driverId;
    }

    return $out;
}

/**
 * @return array{lat:float,lng:float}|null
 */
function getDriverLocation($redis, int $driverId): ?array
{
    $raw = $redis->get('drivers:location:' . $driverId);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || !isset($data['lat'], $data['lng'])) {
        return null;
    }

    return [
        'lat' => (float)$data['lat'],
        'lng' => (float)$data['lng'],
    ];
}

function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

/**
 * Encuentra hotspot sugerido para un conductor por cercanía.
 *
 * @param list<array{zone:string,score:float,grid_id:string,lat:float,lng:float}> $hotspots
 * @return array<string,mixed>|null
 */
function pickHotspotForDriver(array $hotspots, float $driverLat, float $driverLng): ?array
{
    $best = null;
    $bestDistance = PHP_FLOAT_MAX;

    foreach ($hotspots as $hotspot) {
        $dist = haversineKm($driverLat, $driverLng, (float)$hotspot['lat'], (float)$hotspot['lng']);
        if ($dist > REPOSITION_MAX_DISTANCE_KM) {
            continue;
        }

        if ($dist < $bestDistance) {
            $bestDistance = $dist;
            $best = $hotspot;
        }
    }

    if ($best === null) {
        return null;
    }

    $best['distance_km'] = round($bestDistance, 3);
    return $best;
}

function publishRepositionSuggestion($redis, int $driverId, array $hotspot): void
{
    $key = 'driver:reposition:last:' . $driverId;
    $last = $redis->get($key);
    if (is_string($last) && $last === (string)$hotspot['zone']) {
        return;
    }

    $payload = [
        'type' => 'driver_reposition',
        'driver_id' => $driverId,
        'zone' => $hotspot['zone'],
        'grid_id' => $hotspot['grid_id'],
        'target_lat' => $hotspot['lat'],
        'target_lng' => $hotspot['lng'],
        'distance_km' => $hotspot['distance_km'],
        'hotspot_score' => $hotspot['score'],
        'created_at' => gmdate('c'),
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $redis->lPush('notifications:queue', $encoded);
    $redis->publish('notifications:drivers:' . $driverId, $encoded);
    $redis->setex($key, 600, (string)$hotspot['zone']);
}

function runDriverRepositionWorker(): void
{
    $redis = Cache::redis();
    if (!$redis) {
        fwrite(STDERR, "[driver_reposition_worker] Redis no disponible\n");
        exit(1);
    }

    while (true) {
        $loopStart = microtime(true);

        try {
            $hotspots = getTopHotspots($redis);
            if (!empty($hotspots)) {
                $idleDrivers = getIdleDrivers($redis);
                $sent = 0;

                foreach ($idleDrivers as $driverId) {
                    if ($sent >= REPOSITION_MAX_SUGGESTIONS) {
                        break;
                    }

                    $loc = getDriverLocation($redis, $driverId);
                    if (!is_array($loc)) {
                        continue;
                    }

                    $hotspot = pickHotspotForDriver($hotspots, $loc['lat'], $loc['lng']);
                    if ($hotspot === null) {
                        continue;
                    }

                    publishRepositionSuggestion($redis, $driverId, $hotspot);
                    $sent++;
                }
            }

            $loopMs = (int)round((microtime(true) - $loopStart) * 1000);
            $redis->incrBy('metrics:driver_reposition_loop_ms', max(0, $loopMs));
            $redis->incr('metrics:driver_reposition_loop_count');
            $redis->setex('dispatch:driver_reposition_worker:last_heartbeat', 120, (string)time());
        } catch (Throwable $e) {
            error_log('[driver_reposition_worker] ' . $e->getMessage());
        }

        sleep(REPOSITION_LOOP_SEC);
    }
}

runDriverRepositionWorker();
