<?php
/**
 * Worker de surge pricing por grid.
 *
 * Objetivo:
 * - Calcular ratio de demanda por celda (requests/available drivers).
 * - Publicar multiplicador en surge:grid:{grid_id} con TTL de 30s.
 * - No altera tarifas base de empresa; solo aporta factor multiplicador.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/driver_service.php';

const SURGE_KEY_PREFIX = 'surge:grid:';
const SURGE_TTL_SEC = 30;
const SURGE_LOOP_SEC = 2;

/**
 * Convierte ratio de demanda en multiplicador de surge.
 */
function surgeMultiplierFromRatio(float $ratio): float
{
    if ($ratio <= 1.0) return 1.0;
    if ($ratio <= 1.5) return 1.1;
    if ($ratio <= 2.0) return 1.25;
    if ($ratio <= 3.0) return 1.5;
    if ($ratio <= 4.0) return 1.8;
    return 2.0;
}

/**
 * @return list<string>
 */
function activeGridsFromHotspots($redis): array
{
    $members = $redis->zRevRange('dispatch:hotspots:zset', 0, 199, true);
    if (!is_array($members) || empty($members)) {
        return [];
    }

    $grids = [];
    foreach ($members as $zone => $score) {
        $zoneStr = (string)$zone;
        if (strpos($zoneStr, 'zone:') !== 0) {
            continue;
        }
        $gridId = substr($zoneStr, strlen('zone:'));
        if ($gridId !== '') {
            $grids[$gridId] = true;
        }
    }

    return array_keys($grids);
}

/**
 * Cuenta requests de los últimos 10 minutos para un grid.
 */
function demandCount10m($redis, string $gridId): int
{
    $zoneKey = 'zone:' . $gridId;
    $total = 0;
    $nowBogota = function_exists('now_colombia')
        ? now_colombia()
        : new DateTime('now', new DateTimeZone('America/Bogota'));
    for ($i = 0; $i < 10; $i++) {
        $ts = (clone $nowBogota)->modify('-' . $i . ' minutes')->format('YmdHi');
        $bucketKey = 'dispatch:hotspots:bucket:' . $ts;
        $total += (int)$redis->hGet($bucketKey, $zoneKey);
    }
    return max(0, $total);
}

/**
 * Cuenta conductores disponibles y vivos (heartbeat) en el grid.
 */
function availableDriversInGrid($redis, string $gridId): int
{
    $members = $redis->sMembers('drivers:grid:' . $gridId);
    if (!is_array($members) || empty($members)) {
        return 0;
    }

    $count = 0;
    foreach ($members as $driverRaw) {
        $driverId = (int)$driverRaw;
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

        $count++;
    }

    return $count;
}

function runSurgeWorker(): void
{
    $redis = Cache::redis();
    if (!$redis) {
        fwrite(STDERR, "[surge_pricing_worker] Redis no disponible\n");
        exit(1);
    }

    while (true) {
        $loopStart = (int)round(microtime(true) * 1000);

        try {
            $grids = activeGridsFromHotspots($redis);
            foreach ($grids as $gridId) {
                $demand = demandCount10m($redis, $gridId);
                $available = availableDriversInGrid($redis, $gridId);

                $ratio = $available > 0 ? ($demand / max(1, $available)) : ($demand > 0 ? 5.0 : 1.0);
                $multiplier = surgeMultiplierFromRatio($ratio);

                $payload = [
                    'grid_id' => $gridId,
                    'demand_10m' => $demand,
                    'available_drivers' => $available,
                    'ratio' => round($ratio, 3),
                    'multiplier' => $multiplier,
                    'ts' => function_exists('now_colombia')
                        ? now_colombia()->format('c')
                        : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c'),
                ];

                $redis->setex(SURGE_KEY_PREFIX . $gridId, SURGE_TTL_SEC, json_encode($payload, JSON_UNESCAPED_UNICODE));
            }

            $loopMs = max(0, (int)round(microtime(true) * 1000) - $loopStart);
            $redis->incrBy('metrics:surge_loop_ms', $loopMs);
            $redis->incr('metrics:surge_loop_count');
            $redis->setex('dispatch:surge_worker:last_heartbeat', 15, (string)time());
        } catch (Throwable $e) {
            error_log('[surge_pricing_worker] ' . $e->getMessage());
            usleep(250000);
        }

        sleep(SURGE_LOOP_SEC);
    }
}

runSurgeWorker();
