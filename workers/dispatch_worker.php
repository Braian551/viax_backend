<?php
/**
 * Dispatch worker async de ofertas por lotes (production-grade).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/matching_service.php';
require_once __DIR__ . '/../services/driver_service.php';
require_once __DIR__ . '/../services/trip_state_machine.php';

const DISPATCH_QUEUE_KEY = 'dispatch:trip_queue';
const DISPATCH_QUEUE_FALLBACK = 'ride_requests_queue';
const OFFER_BATCH_SIZE = 3;
const OFFER_TIMEOUT_SEC = 8;
const TRIP_CANCEL_TIMEOUT_SEC = 90;
const DRIVER_COOLDOWN_SEC = 30;
const MAX_OFFERS_PER_TRIP = 15;
const DISPATCH_TARGET_MS = 50;
const DRIVER_SEARCH_TARGET_MS = 30;
const DISPATCH_LOG_FILE = __DIR__ . '/dispatch_worker.log';

function nowMs(): int
{
    return (int)round(microtime(true) * 1000);
}

/**
 * Escribe una línea JSON para observabilidad de dispatch.
 *
 * @param array<string,mixed> $payload
 */
function writeDispatchLog(array $payload): void
{
    $payload['ts'] = gmdate('c');
    @file_put_contents(DISPATCH_LOG_FILE, json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
}

function fetchRideRequest(PDO $db, int $requestId): ?array
{
    $stmt = $db->prepare(
        "SELECT id, estado, cliente_id, tipo_vehiculo, empresa_id, latitud_recogida, longitud_recogida
         FROM solicitudes_servicio
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function isRequestStillPending(array $ride): bool
{
    $estado = strtolower(trim((string)($ride['estado'] ?? '')));
    return in_array($estado, ['pendiente', 'requested'], true);
}

function buildRankedDrivers(PDO $db, array $ride, float $radiusKm): array
{
    $redis = Cache::redis();
    $lat = (float)$ride['latitud_recogida'];
    $lng = (float)$ride['longitud_recogida'];
    $cityId = DriverGeoService::getCityIdFromCoordinates($lat, $lng);
    $gridId = DriverGeoService::gridIdForCoordinates($lat, $lng);
    $zoneGridId = 'c' . $cityId . ':' . $gridId;

    if ($redis) {
        $zoneKey = 'dispatch:zone_drivers:' . $zoneGridId;
        $cached = $redis->zRevRange($zoneKey, 0, 19, true);
        if (!is_array($cached) || empty($cached)) {
            // Fallback para claves previas sin ciudad.
            $cached = $redis->zRevRange('dispatch:zone_drivers:' . $gridId, 0, 19, true);
        }
        if (is_array($cached) && !empty($cached)) {
            $cachedIds = [];
            foreach ($cached as $driverId => $score) {
                $id = (int)$driverId;
                if ($id > 0) {
                    $cachedIds[] = $id;
                }
            }

            if (!empty($cachedIds)) {
                $redis->incr('metrics:zone_cache_hit');
                $redis->incr('metrics:dispatch_cache_hits');
                return RideMatchingService::rankCandidatesFromIds(
                    $db,
                    $lat,
                    $lng,
                    $cachedIds,
                    20,
                    (string)($ride['tipo_vehiculo'] ?? 'moto'),
                    isset($ride['empresa_id']) ? (int)$ride['empresa_id'] : null
                );
            }
        }

        $redis->incr('metrics:zone_cache_miss');
        $redis->incr('metrics:dispatch_cache_misses');
    }

    return RideMatchingService::rankCandidates(
        $db,
        $lat,
        $lng,
        $radiusKm,
        20,
        (string)($ride['tipo_vehiculo'] ?? 'moto'),
        isset($ride['empresa_id']) ? (int)$ride['empresa_id'] : null
    );
}

function setDriverCooldown($redis, int $driverId, int $ttl = DRIVER_COOLDOWN_SEC): void
{
    $redis->setex('driver:cooldown:' . $driverId, $ttl, '1');
}

function cancelRideRequest(PDO $db, $redis, int $requestId, string $reason): void
{
    try {
        $stmt = $db->prepare(
            "UPDATE solicitudes_servicio
             SET estado = 'cancelada'
             WHERE id = :id
               AND LOWER(estado) IN ('pendiente', 'requested')"
        );
        $stmt->execute([':id' => $requestId]);

        Cache::set('ride:' . $requestId . ':cancel_reason', $reason, 300);
        $redis->publish('trip:events:' . $requestId, json_encode([
            'trip_id' => $requestId,
            'event' => 'trip_cancelled',
            'reason' => $reason,
            'timestamp' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        error_log('[dispatch_worker][cancel] ' . $e->getMessage());
    }
}

function tryOfferDriver($redis, int $requestId, int $driverId): bool
{
    if ($redis->exists('driver:cooldown:' . $driverId)) {
        return false;
    }

    $offeredSetKey = 'trip:offered_drivers:' . $requestId;
    if ($redis->sIsMember($offeredSetKey, (string)$driverId)) {
        return false;
    }

    $lockKey = 'driver_offer_lock:' . $driverId;
    $locked = $redis->setnx($lockKey, (string)$requestId);
    if (!$locked) {
        return false;
    }

    $redis->expire($lockKey, 10);

    $redis->sAdd($offeredSetKey, (string)$driverId);
    $redis->expire($offeredSetKey, 600);

    $redis->incr('metrics:offers_sent');
    $redis->setex('ride:' . $requestId . ':offer_ts:' . $driverId, 120, (string)nowMs());

    Cache::set('ride:' . $requestId . ':current_offer', (string)json_encode([
        'driver_id' => $driverId,
        'offered_at' => gmdate('c'),
        'timeout_sec' => OFFER_TIMEOUT_SEC,
    ], JSON_UNESCAPED_UNICODE), 30);

    // Publicar oferta para app conductor (si existe subscriber).
    $redis->publish('trip:offers:' . $requestId, json_encode([
        'trip_id' => $requestId,
        'driver_id' => $driverId,
        'offered_at' => gmdate('c'),
        'timeout_sec' => OFFER_TIMEOUT_SEC,
    ], JSON_UNESCAPED_UNICODE));

    return true;
}

function tryAcquireTripLock($redis, int $tripId, int $driverId): bool
{
    $lockKey = 'trip_lock:' . $tripId;
    $ok = $redis->setnx($lockKey, (string)$driverId);
    if ($ok) {
        $redis->expire($lockKey, 120);
    }
    return (bool)$ok;
}

/**
 * @return array{accepted:bool,rejected:bool,accept_time_ms:int}
 */
function waitForAcceptance(PDO $db, $redis, int $requestId, int $driverId, int $timeoutSec = OFFER_TIMEOUT_SEC): array
{
    $deadline = time() + $timeoutSec;
    $responseQueueKey = 'trip:responses_queue:' . $requestId;
    $offerTsRaw = $redis->get('ride:' . $requestId . ':offer_ts:' . $driverId);
    $offerTsMs = is_string($offerTsRaw) && is_numeric($offerTsRaw) ? (int)$offerTsRaw : nowMs();

    while (time() < $deadline) {
        $responseRaw = $redis->rPop($responseQueueKey);
        if (is_string($responseRaw) && trim($responseRaw) !== '') {
            $resp = json_decode($responseRaw, true);
            $responseDriverId = is_array($resp) && isset($resp['driver_id']) ? (int)$resp['driver_id'] : (int)$responseRaw;
            $statusRaw = is_array($resp)
                ? strtolower((string)($resp['status'] ?? $resp['action'] ?? 'accepted'))
                : 'accepted';

            if ($responseDriverId === $driverId && in_array($statusRaw, ['rejected', 'reject', 'ignored', 'timeout'], true)) {
                return [
                    'accepted' => false,
                    'rejected' => true,
                    'accept_time_ms' => max(0, nowMs() - $offerTsMs),
                ];
            }

            if ($responseDriverId === $driverId && in_array($statusRaw, ['accepted', 'accept'], true) && tryAcquireTripLock($redis, $requestId, $driverId)) {
                return [
                    'accepted' => true,
                    'rejected' => false,
                    'accept_time_ms' => max(0, nowMs() - $offerTsMs),
                ];
            }
        }

        $acceptedRaw = Cache::get('ride:' . $requestId . ':accepted_driver');
        if (is_string($acceptedRaw) && intval($acceptedRaw) === $driverId && tryAcquireTripLock($redis, $requestId, $driverId)) {
            return [
                'accepted' => true,
                'rejected' => false,
                'accept_time_ms' => max(0, nowMs() - $offerTsMs),
            ];
        }

        $stmt = $db->prepare('SELECT estado FROM solicitudes_servicio WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $requestId]);
        $estado = strtolower((string)($stmt->fetchColumn() ?: ''));
        if (in_array($estado, ['aceptada', 'asignado', 'driver_assigned'], true)) {
            return [
                'accepted' => true,
                'rejected' => false,
                'accept_time_ms' => max(0, nowMs() - $offerTsMs),
            ];
        }

        usleep(250000);
    }

    $redis->incr('metrics:dispatch_timeouts');
    return [
        'accepted' => false,
        'rejected' => false,
        'accept_time_ms' => max(0, nowMs() - $offerTsMs),
    ];
}

function markAssignedCache(int $requestId, int $driverId): void
{
    Cache::set('ride_request:' . $requestId, (string)json_encode([
        'solicitud_id' => $requestId,
        'conductor_id' => $driverId,
        'estado' => 'aceptada',
        'timestamp' => time(),
    ], JSON_UNESCAPED_UNICODE), 300);
}

$redis = Cache::redis();
if (!$redis) {
    fwrite(STDERR, "[dispatch_worker] Redis no disponible\n");
    exit(1);
}

$database = new Database();
$db = $database->getConnection();

while (true) {
    try {
        $entry = $redis->brPop([DISPATCH_QUEUE_KEY, DISPATCH_QUEUE_FALLBACK], 3);
        if (!is_array($entry) || !isset($entry[1])) {
            continue;
        }

        $requestId = intval($entry[1]);
        if ($requestId <= 0) {
            continue;
        }

        $ride = fetchRideRequest($db, $requestId);
        if (!$ride || !isRequestStillPending($ride)) {
            continue;
        }

        $dispatchStartMs = nowMs();
        $accepted = false;
        $driverAcceptedId = null;
        $offerCursor = 0;
        $offersSentForTrip = 0;
        $driversOffered = 0;
        $dispatchDeadline = time() + TRIP_CANCEL_TIMEOUT_SEC;
        $cityId = DriverGeoService::getCityIdFromCoordinates((float)$ride['latitud_recogida'], (float)$ride['longitud_recogida']);

        $searchStartMs = nowMs();
        $rankedAll = buildRankedDrivers($db, $ride, 8.0);
        $searchLatencyMs = max(0, nowMs() - $searchStartMs);
        $redis->incrBy('metrics:driver_search_latency', $searchLatencyMs);
        $redis->incr('metrics:driver_search_latency_count');
        if ($searchLatencyMs > DRIVER_SEARCH_TARGET_MS) {
            $redis->incr('metrics:driver_search_sla_breach');
        }

        if (empty($rankedAll)) {
            // Failsafe: sin candidatos por grid, reintentar por GEO más amplio.
            $rankedAll = RideMatchingService::rankCandidates(
                $db,
                (float)$ride['latitud_recogida'],
                (float)$ride['longitud_recogida'],
                12.0,
                30,
                (string)($ride['tipo_vehiculo'] ?? 'moto'),
                isset($ride['empresa_id']) ? (int)$ride['empresa_id'] : null
            );
        }

        $driversFound = count($rankedAll);
        $redis->incrBy('metrics:drivers_scanned', $driversFound);

        $driverIds = array_values(array_map(static fn($x) => (int)$x['driver_id'], $rankedAll));
        $redis->setex('ride:' . $requestId . ':drivers', 600, json_encode($driverIds, JSON_UNESCAPED_UNICODE));
        $redis->expire('trip:offered_drivers:' . $requestId, 600);

        while (!$accepted && time() < $dispatchDeadline && $offerCursor < count($rankedAll) && $offersSentForTrip < MAX_OFFERS_PER_TRIP) {
            $remainingOffers = MAX_OFFERS_PER_TRIP - $offersSentForTrip;
            $batch = array_slice($rankedAll, $offerCursor, min(OFFER_BATCH_SIZE, $remainingOffers));
            $offerCursor += count($batch);
            $offeredDriverIds = [];

            foreach ($batch as $candidate) {
                $driverId = (int)$candidate['driver_id'];
                if ($driverId <= 0) {
                    continue;
                }
                if ($offersSentForTrip >= MAX_OFFERS_PER_TRIP) {
                    break;
                }

                if (!DriverGeoService::isDriverAvailable($redis, $driverId, $cityId)) {
                    continue;
                }

                if ($redis->exists('driver:cooldown:' . $driverId)) {
                    continue;
                }

                if (!tryOfferDriver($redis, $requestId, $driverId)) {
                    continue;
                }

                $offeredDriverIds[] = $driverId;
                $offersSentForTrip++;
                $driversOffered++;
            }

            foreach ($offeredDriverIds as $driverId) {
                $acceptResult = waitForAcceptance($db, $redis, $requestId, $driverId);

                if ($acceptResult['accepted']) {
                    markAssignedCache($requestId, $driverId);
                    $redis->incr('metrics:acceptance_rate');
                    $redis->incrBy('metrics:driver_accept_time', max(0, (int)$acceptResult['accept_time_ms']));
                    $redis->incr('metrics:driver_accept_time_count');
                    $accepted = true;
                    $driverAcceptedId = $driverId;
                    break;
                }

                $redis->del('driver_offer_lock:' . $driverId);
                setDriverCooldown($redis, $driverId);
                DriverGeoService::updateDriverStats($driverId, null, 0.15, null);
            }

            if ($accepted) {
                break;
            }

            if (empty($offeredDriverIds)) {
                usleep(200000);
                continue;
            }

            // Esperar ventana de batch antes de siguiente lote.
            sleep(OFFER_TIMEOUT_SEC);
        }

        if (!$accepted && $offersSentForTrip >= MAX_OFFERS_PER_TRIP) {
            $redis->incr('metrics:dispatch_cancelled_max_offers');
            cancelRideRequest($db, $redis, $requestId, 'max_offers_exceeded');
        }

        if (!$accepted && time() >= $dispatchDeadline) {
            $redis->incr('metrics:dispatch_expired');
            cancelRideRequest($db, $redis, $requestId, 'dispatch_timeout');
        }

        $dispatchMs = max(0, nowMs() - $dispatchStartMs);
        $redis->incrBy('metrics:dispatch_time', $dispatchMs);
        $redis->incrBy('metrics:dispatch_latency_ms', $dispatchMs);
        $redis->incr('metrics:dispatch_time_count');
        if ($dispatchMs > DISPATCH_TARGET_MS) {
            $redis->incr('metrics:dispatch_sla_breach');
        }

        writeDispatchLog([
            'trip_id' => $requestId,
            'drivers_found' => $driversFound,
            'drivers_offered' => $driversOffered,
            'driver_accepted' => $driverAcceptedId,
            'dispatch_time_ms' => $dispatchMs,
        ]);
    } catch (Throwable $e) {
        error_log('[dispatch_worker] ' . $e->getMessage());
        writeDispatchLog([
            'trip_id' => null,
            'drivers_found' => 0,
            'drivers_offered' => 0,
            'driver_accepted' => null,
            'dispatch_time_ms' => 0,
            'error' => $e->getMessage(),
        ]);
        usleep(300000);
    }
}
