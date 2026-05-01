<?php
/**
 * Worker de surge pricing por grid.
 *
 * Objetivo:
 * - Calcular demanda real por zona (solicitudes activas / conductores disponibles).
 * - No activar surge con baja demanda (umbral mínimo configurable).
 * - Aplicar suavizado y cooldown para evitar saltos bruscos.
 * - Publicar métricas en Redis y mantener compatibilidad con `surge:grid:{grid_id}`.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/driver_service.php';
require_once __DIR__ . '/../services/pricing_service.php';

const SURGE_KEY_PREFIX = 'surge:grid:';
const ZONE_KEY_PREFIX = 'zone:';
const SURGE_METRIC_TTL_SEC = 120;
const SURGE_PAYLOAD_TTL_SEC = 180;

/**
 * Intervalo de cálculo del worker (cooldown operativo).
 */
function surgeWorkerLoopSeconds(): int
{
    $raw = env_value('SURGE_WORKER_LOOP_SECONDS', '45');
    $parsed = intval($raw);
    return max(30, min(60, $parsed));
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
 * @return list<string>
 */
function activeGridsFromRedisMetrics($redis): array
{
    $suffixes = ['active_requests', 'available_drivers', 'recent_requests', 'recent_requests_count', 'surge_multiplier'];
    $grids = [];

    foreach ($suffixes as $suffix) {
        $keys = $redis->keys('zone:*:' . $suffix);
        if (!is_array($keys) || empty($keys)) {
            continue;
        }

        foreach ($keys as $rawKey) {
            $key = (string)$rawKey;
            $gridId = extractGridIdFromMetricKey($key, $suffix);
            if ($gridId !== null) {
                $grids[$gridId] = true;
            }
        }
    }

    return array_keys($grids);
}

/**
 * @return list<string>
 */
function activeGridsFromAvailableDrivers($redis): array
{
    $members = $redis->sMembers('drivers:available');
    if (!is_array($members) || empty($members)) {
        return [];
    }

    $grids = [];
    foreach ($members as $driverRaw) {
        $driverId = (int)$driverRaw;
        if ($driverId <= 0) {
            continue;
        }

        $gridCityKey = $redis->get('drivers:last_grid_city:' . $driverId);
        if (is_string($gridCityKey) && $gridCityKey !== '') {
            $normalized = str_replace('drivers:grid:', '', $gridCityKey);
            $parts = explode(':', $normalized, 3);
            if (count($parts) === 3) {
                $gridId = $parts[1] . ':' . $parts[2];
                if ($gridId !== '') {
                    $grids[$gridId] = true;
                    continue;
                }
            }
        }

        $legacyGrid = $redis->get('drivers:last_grid:' . $driverId);
        if (is_string($legacyGrid) && $legacyGrid !== '') {
            $gridId = str_replace('drivers:grid:', '', $legacyGrid);
            if ($gridId !== '' && strpos($gridId, ':') !== false) {
                $grids[$gridId] = true;
            }
        }
    }

    return array_keys($grids);
}

function extractGridIdFromMetricKey(string $key, string $suffix): ?string
{
    $prefix = 'zone:';
    $suffixToken = ':' . $suffix;

    if (strpos($key, $prefix) !== 0 || substr($key, -strlen($suffixToken)) !== $suffixToken) {
        return null;
    }

    $gridId = substr($key, strlen($prefix), -strlen($suffixToken));
    if (!is_string($gridId) || trim($gridId) === '') {
        return null;
    }

    return $gridId;
}

/**
 * @param list<string> $states
 * @return array<string,int>
 */
function fetchRequestCountsByGrid(PDO $db, array $states, string $intervalSpec): array
{
    if (empty($states)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($states), '?'));
    $sql = "
        SELECT
            CONCAT(FLOOR(latitud_recogida * 200), ':', FLOOR(longitud_recogida * 200)) AS grid_id,
            COUNT(*) AS total
        FROM solicitudes_servicio
        WHERE estado IN ($placeholders)
          AND latitud_recogida IS NOT NULL
          AND longitud_recogida IS NOT NULL
          AND COALESCE(solicitado_en, fecha_creacion) >= NOW() - INTERVAL '$intervalSpec'
        GROUP BY 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($states);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $gridId = isset($row['grid_id']) ? (string)$row['grid_id'] : '';
        if ($gridId === '') {
            continue;
        }

        $map[$gridId] = (int)($row['total'] ?? 0);
    }

    return $map;
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

function readZoneMetricInt($redis, string $gridId, string $suffix): int
{
    $raw = $redis->get(ZONE_KEY_PREFIX . $gridId . ':' . $suffix);
    if (!is_string($raw) || !is_numeric($raw)) {
        return 0;
    }

    return max(0, (int)$raw);
}

function readPreviousMultiplier($redis, string $gridId): float
{
    $zoneRaw = $redis->get(ZONE_KEY_PREFIX . $gridId . ':surge_multiplier');
    if (is_string($zoneRaw) && is_numeric($zoneRaw)) {
        return max(1.0, (float)$zoneRaw);
    }

    $compatRaw = $redis->get(SURGE_KEY_PREFIX . $gridId);
    $compatData = is_string($compatRaw) ? json_decode($compatRaw, true) : null;
    if (is_array($compatData) && isset($compatData['multiplier'])) {
        return max(1.0, (float)$compatData['multiplier']);
    }

    return 1.0;
}

/**
 * @param array<string,mixed> $payload
 */
function publishZonePayload($redis, string $gridId, array $payload): void
{
    $zonePrefix = ZONE_KEY_PREFIX . $gridId . ':';

    $redis->setex($zonePrefix . 'active_requests', SURGE_METRIC_TTL_SEC, (string)max(0, (int)($payload['active_requests'] ?? 0)));
    $redis->setex($zonePrefix . 'available_drivers', SURGE_METRIC_TTL_SEC, (string)max(0, (int)($payload['available_drivers'] ?? 0)));
    $redis->setex($zonePrefix . 'recent_requests', SURGE_METRIC_TTL_SEC, (string)max(0, (int)($payload['recent_requests'] ?? 0)));
    $redis->setex($zonePrefix . 'recent_requests_count', SURGE_METRIC_TTL_SEC, (string)max(0, (int)($payload['recent_requests'] ?? 0)));
    $redis->setex($zonePrefix . 'surge_multiplier', SURGE_METRIC_TTL_SEC, (string)max(1.0, (float)($payload['multiplier'] ?? 1.0)));

    $redis->setex(
        SURGE_KEY_PREFIX . $gridId,
        SURGE_PAYLOAD_TTL_SEC,
        json_encode($payload, JSON_UNESCAPED_UNICODE)
    );
}

function runSurgeWorker(): void
{
    $redis = Cache::redis();
    if (!$redis) {
        fwrite(STDERR, "[surge_pricing_worker] Redis no disponible\n");
        exit(1);
    }

    $db = (new Database())->getConnection();

    $activeStates = ['pendiente', 'aceptada', 'aceptado', 'asignado', 'en_camino', 'conductor_llego'];
    $loopSec = surgeWorkerLoopSeconds();

    while (true) {
        $loopStart = (int)round(microtime(true) * 1000);

        try {
            $activeByGridDb = fetchRequestCountsByGrid($db, $activeStates, '120 minutes');
            $recentByGridDb = fetchRequestCountsByGrid($db, $activeStates, '5 minutes');

            $activeGrids = [];
            foreach (activeGridsFromHotspots($redis) as $gridId) {
                $activeGrids[$gridId] = true;
            }
            foreach (activeGridsFromRedisMetrics($redis) as $gridId) {
                $activeGrids[$gridId] = true;
            }
            foreach (activeGridsFromAvailableDrivers($redis) as $gridId) {
                $activeGrids[$gridId] = true;
            }
            foreach ($activeByGridDb as $gridId => $count) {
                $activeGrids[$gridId] = true;
            }
            foreach ($recentByGridDb as $gridId => $count) {
                $activeGrids[$gridId] = true;
            }

            foreach (array_keys($activeGrids) as $gridId) {
                $activeDb = $activeByGridDb[$gridId] ?? 0;
                $activeRedis = readZoneMetricInt($redis, $gridId, 'active_requests');

                $recentDb = $recentByGridDb[$gridId] ?? 0;
                $recentRedis = max(
                    readZoneMetricInt($redis, $gridId, 'recent_requests'),
                    readZoneMetricInt($redis, $gridId, 'recent_requests_count')
                );
                $recentRequests = max($recentDb, $recentRedis);

                $activeRequests = max($activeDb, $activeRedis, $recentRequests);

                $availableByGrid = availableDriversInGrid($redis, $gridId);
                $availableMetric = readZoneMetricInt($redis, $gridId, 'available_drivers');
                $availableDrivers = $availableByGrid > 0
                    ? $availableByGrid
                    : max(0, $availableMetric);

                $ratio = $availableDrivers > 0
                    ? ((float)$activeRequests / (float)$availableDrivers)
                    : ($activeRequests > 0 ? 99.0 : 0.0);

                $target = DynamicPricingService::resolveSurgeTarget($ratio, $activeRequests, $availableDrivers);
                $previous = readPreviousMultiplier($redis, $gridId);
                $multiplier = DynamicPricingService::smoothSurge($previous, $target);

                $payload = [
                    'grid_id' => $gridId,
                    'zone_id' => ZONE_KEY_PREFIX . $gridId,
                    'active_requests' => $activeRequests,
                    'recent_requests' => $recentRequests,
                    'available_drivers' => $availableDrivers,
                    'ratio' => round($ratio, 4),
                    'previous_multiplier' => round($previous, 2),
                    'target_multiplier' => round($target, 2),
                    'multiplier' => round($multiplier, 2),
                    'demand_level' => DynamicPricingService::demandLevel($multiplier),
                    'message' => DynamicPricingService::demandMessage($multiplier),
                    'cooldown_seconds' => $loopSec,
                    'updated_at' => function_exists('now_colombia')
                        ? now_colombia()->format('c')
                        : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c'),
                ];

                publishZonePayload($redis, $gridId, $payload);
            }

            $loopMs = max(0, (int)round(microtime(true) * 1000) - $loopStart);
            $redis->incrBy('metrics:surge_loop_ms', $loopMs);
            $redis->incr('metrics:surge_loop_count');
            $redis->setex('dispatch:surge_worker:last_heartbeat', max(15, $loopSec + 15), (string)time());
        } catch (Throwable $e) {
            error_log('[surge_pricing_worker] ' . $e->getMessage());
            usleep(250000);
        }

        sleep($loopSec);
    }
}

runSurgeWorker();
