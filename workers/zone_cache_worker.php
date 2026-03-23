<?php
/**
 * Worker de cache por zona para dispatch de baja latencia.
 *
 * - Recalcula cada 2s los top conductores por grid activo.
 * - Escribe en ZSET: dispatch:zone_drivers:{grid_id}
 * - TTL de cache: 10s.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/matching_service.php';
require_once __DIR__ . '/../services/driver_service.php';

const ZONE_CACHE_PREFIX = 'dispatch:zone_drivers:';
const ZONE_CACHE_TTL_SEC = 10;
const ZONE_CACHE_TOP_LIMIT = 20;
const ZONE_CACHE_LOOP_SEC = 2;

/**
 * @return list<array{city_id:int,grid_id:string,zone_grid_id:string}>
 */
function gatherActiveGridIds($redis): array
{
    $grids = [];

    try {
        $hotspots = $redis->zRevRange('dispatch:hotspots:zset', 0, 199);
        if (is_array($hotspots)) {
            foreach ($hotspots as $zone) {
                $zoneStr = (string)$zone;
                if (strpos($zoneStr, 'zone:') !== 0) {
                    continue;
                }
                $gridId = substr($zoneStr, strlen('zone:'));
                if ($gridId !== '') {
                    [$lat, $lng] = DriverGeoService::gridCenterFromId($gridId);
                    $cityId = DriverGeoService::getCityIdFromCoordinates($lat, $lng);
                    $zoneGridId = 'c' . $cityId . ':' . $gridId;
                    $grids[$zoneGridId] = [
                        'city_id' => $cityId,
                        'grid_id' => $gridId,
                        'zone_grid_id' => $zoneGridId,
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[zone_cache_worker][hotspots] ' . $e->getMessage());
    }

    try {
        $available = $redis->sMembers('drivers:available');
        if (is_array($available)) {
            foreach ($available as $driverRaw) {
                $driverId = (int)$driverRaw;
                if ($driverId <= 0) {
                    continue;
                }

                $gridKeyCity = $redis->get('drivers:last_grid_city:' . $driverId);
                if (is_string($gridKeyCity) && $gridKeyCity !== '') {
                    $raw = str_replace('drivers:grid:', '', $gridKeyCity);
                    $parts = explode(':', $raw, 3);
                    if (count($parts) === 3) {
                        $cityId = (int)$parts[0];
                        $gridId = $parts[1] . ':' . $parts[2];
                        if ($cityId > 0) {
                            $zoneGridId = 'c' . $cityId . ':' . $gridId;
                            $grids[$zoneGridId] = [
                                'city_id' => $cityId,
                                'grid_id' => $gridId,
                                'zone_grid_id' => $zoneGridId,
                            ];
                            continue;
                        }
                    }
                }

                // Compatibilidad con esquema legacy sin ciudad.
                $gridKey = $redis->get('drivers:last_grid:' . $driverId);
                if (is_string($gridKey) && $gridKey !== '') {
                    $gridId = str_replace('drivers:grid:', '', $gridKey);
                    if ($gridId !== '' && strpos($gridId, ':') !== false) {
                        [$lat, $lng] = DriverGeoService::gridCenterFromId($gridId);
                        $cityId = DriverGeoService::getCityIdFromCoordinates($lat, $lng);
                        $zoneGridId = 'c' . $cityId . ':' . $gridId;
                        $grids[$zoneGridId] = [
                            'city_id' => $cityId,
                            'grid_id' => $gridId,
                            'zone_grid_id' => $zoneGridId,
                        ];
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[zone_cache_worker][available] ' . $e->getMessage());
    }

    return array_values($grids);
}

/**
 * @return list<int>
 */
function collectDriversForGrid($redis, int $cityId, string $gridId): array
{
    $gridKey = DriverGeoService::gridCellKeyByCity($cityId, $gridId);
    $members = $redis->sMembers($gridKey);
    if (!is_array($members) || empty($members)) {
        // Fallback clave legacy.
        $members = $redis->sMembers('drivers:grid:' . $gridId);
    }
    if (!is_array($members) || empty($members)) {
        return [];
    }

    $ids = [];
    foreach ($members as $member) {
        $driverId = (int)$member;
        if ($driverId <= 0) {
            continue;
        }

        if (!DriverGeoService::isDriverAvailable($redis, $driverId, $cityId)) {
            continue;
        }

        if (!$redis->exists('driver:heartbeat:' . $driverId)) {
            DriverGeoService::removeDriverFromRealtimeIndexes($driverId, $cityId);
            continue;
        }

        if ($redis->exists('driver_offer_lock:' . $driverId) || $redis->exists('driver:cooldown:' . $driverId)) {
            continue;
        }

        $ids[] = $driverId;
    }

    return $ids;
}

/**
 * @param array<int,array<string,mixed>> $ranked
 */
function storeZoneCache($redis, string $zoneGridId, string $gridId, array $ranked): void
{
    $zoneKey = ZONE_CACHE_PREFIX . $zoneGridId;
    $redis->del($zoneKey);

    $added = 0;
    foreach ($ranked as $row) {
        if ($added >= ZONE_CACHE_TOP_LIMIT) {
            break;
        }

        $driverId = (int)($row['driver_id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }

        $score = (float)($row['score'] ?? 0.0);
        $redis->zAdd($zoneKey, $score, (string)$driverId);
        $added++;
    }

    $redis->expire($zoneKey, ZONE_CACHE_TTL_SEC);

    // Mantener copia corta legacy para consumidores antiguos que aún consulten por grid puro.
    $legacyKey = ZONE_CACHE_PREFIX . $gridId;
    $redis->del($legacyKey);
    $top = $redis->zRevRange($zoneKey, 0, ZONE_CACHE_TOP_LIMIT - 1, true);
    if (is_array($top) && !empty($top)) {
        foreach ($top as $member => $score) {
            $redis->zAdd($legacyKey, (float)$score, (string)$member);
        }
        $redis->expire($legacyKey, ZONE_CACHE_TTL_SEC);
    }
}

function runZoneCacheWorker(): void
{
    $redis = Cache::redis();
    if (!$redis) {
        fwrite(STDERR, "[zone_cache_worker] Redis no disponible\n");
        exit(1);
    }

    $db = (new Database())->getConnection();

    while (true) {
        $loopStart = (int)round(microtime(true) * 1000);

        try {
            $grids = gatherActiveGridIds($redis);
            foreach ($grids as $gridMeta) {
                $cityId = (int)$gridMeta['city_id'];
                $gridId = (string)$gridMeta['grid_id'];
                $zoneGridId = (string)$gridMeta['zone_grid_id'];

                [$centerLat, $centerLng] = DriverGeoService::gridCenterFromId($gridId);
                if ($centerLat === 0.0 && $centerLng === 0.0) {
                    continue;
                }

                $driverIds = collectDriversForGrid($redis, $cityId, $gridId);
                if (empty($driverIds)) {
                    $redis->del(ZONE_CACHE_PREFIX . $zoneGridId);
                    continue;
                }

                $ranked = RideMatchingService::rankCandidatesFromIds(
                    $db,
                    $centerLat,
                    $centerLng,
                    $driverIds,
                    ZONE_CACHE_TOP_LIMIT,
                    null,
                    null
                );

                storeZoneCache($redis, $zoneGridId, $gridId, $ranked);
            }

            $loopMs = max(0, (int)round(microtime(true) * 1000) - $loopStart);
            $redis->incrBy('metrics:zone_cache_worker_loop_ms', $loopMs);
            $redis->incr('metrics:zone_cache_worker_loop_count');
            $redis->setex('dispatch:zone_cache_worker:last_heartbeat', 15, (string)time());
        } catch (Throwable $e) {
            error_log('[zone_cache_worker] ' . $e->getMessage());
            usleep(250000);
        }

        sleep(ZONE_CACHE_LOOP_SEC);
    }
}

runZoneCacheWorker();
