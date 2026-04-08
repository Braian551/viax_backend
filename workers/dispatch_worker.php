<?php
/**
 * Dispatch worker async de ofertas por lotes (production-grade).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/matching_service.php';
require_once __DIR__ . '/../services/driver_service.php';
require_once __DIR__ . '/../services/trip_state_machine.php';
require_once __DIR__ . '/../services/RealtimeEventPublisher.php';

const DISPATCH_QUEUE_KEY = 'dispatch:trip_queue';
const DISPATCH_QUEUE_FALLBACK = 'ride_requests_queue';
const OFFER_BATCH_SIZE = 1;
const DRIVER_OFFER_TTL_SEC = 25;
const UI_ROTATION_SEC = 5;
const OFFER_TIMEOUT_SEC = DRIVER_OFFER_TTL_SEC;
const TRIP_CANCEL_TIMEOUT_SEC = 90;
const DRIVER_COOLDOWN_SEC = 30;
const MAX_OFFERS_PER_TRIP = 15;
const DISPATCH_TARGET_MS = 50;
const DRIVER_SEARCH_TARGET_MS = 30;
const DISPATCH_LOG_FILE = __DIR__ . '/dispatch_worker.log';
const TRIP_PROCESS_LOCK_TTL_SEC = 5;
const DRIVER_STATUS_TTL_SEC = DRIVER_OFFER_TTL_SEC;
const DRIVER_STATUS_FINAL_TTL_SEC = 180;
const CURRENT_DRIVER_TTL_SEC = 180;
const MATCH_RADIUS_KM_INITIAL = 8.0;
const MATCH_RADIUS_RETRY_MULTIPLIER = 1.5;

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
    $payload['ts'] = function_exists('now_colombia')
        ? now_colombia()->format('c')
        : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c');
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
            'timestamp' => function_exists('now_colombia')
                ? now_colombia()->format('c')
                : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c'),
        ], JSON_UNESCAPED_UNICODE));

        // Publicar al gateway WebSocket
        try {
            $stmtCli = $db->prepare('SELECT cliente_id FROM solicitudes_servicio WHERE id = :id LIMIT 1');
            $stmtCli->execute([':id' => $requestId]);
            $cliRow = $stmtCli->fetch(PDO::FETCH_ASSOC);
            $clienteId = (int)($cliRow['cliente_id'] ?? 0);
            RealtimeEventPublisher::searchStatusChanged($requestId, $clienteId, 'cancelada', ['reason' => $reason]);
        } catch (Throwable $rtErr) {
            error_log('[dispatch_worker][rt_cancel] ' . $rtErr->getMessage());
        }
    } catch (Throwable $e) {
        error_log('[dispatch_worker][cancel] ' . $e->getMessage());
    }
}

function markNoDriversAvailable(PDO $db, $redis, int $requestId): void
{
    try {
        $stmt = $db->prepare(
            "UPDATE solicitudes_servicio
             SET estado = 'sin_conductores'
             WHERE id = :id
               AND LOWER(estado) IN ('pendiente', 'requested')"
        );
        $stmt->execute([':id' => $requestId]);

        Cache::set('ride:' . $requestId . ':cancel_reason', 'no_drivers_available', 300);
        $redis->setex('ride:' . $requestId . ':matching_status', 180, 'sin_conductores');
        $redis->publish('trip:events:' . $requestId, json_encode([
            'trip_id' => $requestId,
            'event' => 'no_drivers_available',
            'status' => 'sin_conductores',
            'timestamp' => function_exists('now_colombia')
                ? now_colombia()->format('c')
                : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c'),
        ], JSON_UNESCAPED_UNICODE));

        // Publicar al gateway WebSocket
        try {
            $stmtCli = $db->prepare('SELECT cliente_id FROM solicitudes_servicio WHERE id = :id LIMIT 1');
            $stmtCli->execute([':id' => $requestId]);
            $cliRow = $stmtCli->fetch(PDO::FETCH_ASSOC);
            $clienteId = (int)($cliRow['cliente_id'] ?? 0);
            RealtimeEventPublisher::searchStatusChanged($requestId, $clienteId, 'sin_conductores');
        } catch (Throwable $rtErr) {
            error_log('[dispatch_worker][rt_no_drivers] ' . $rtErr->getMessage());
        }
    } catch (Throwable $e) {
        error_log('[dispatch_worker][no_drivers] ' . $e->getMessage());
    }
}

function setMatchingStatus($redis, int $requestId, string $status, int $ttl = 120): void
{
    try {
        $normalized = trim(strtolower($status));
        if ($normalized === '') {
            return;
        }
        $redis->setex('ride:' . $requestId . ':matching_status', $ttl, $normalized);
    } catch (Throwable $e) {
    }
}

function acquireTripProcessLock($redis, int $tripId, int $ttl = TRIP_PROCESS_LOCK_TTL_SEC): bool
{
    $lockKey = 'ride:' . $tripId . ':lock';
    try {
        $ok = $redis->set($lockKey, '1', ['NX', 'EX' => $ttl]);
        return $ok === true || strtoupper((string)$ok) === 'OK';
    } catch (Throwable $e) {
        $ok = $redis->setnx($lockKey, '1');
        if ($ok) {
            $redis->expire($lockKey, $ttl);
        }
        return (bool)$ok;
    }
}

function refreshTripProcessLock($redis, int $tripId, int $ttl = TRIP_PROCESS_LOCK_TTL_SEC): void
{
    try {
        $redis->expire('ride:' . $tripId . ':lock', $ttl);
    } catch (Throwable $e) {
    }
}

function releaseTripProcessLock($redis, int $tripId): void
{
    try {
        $redis->del('ride:' . $tripId . ':lock');
    } catch (Throwable $e) {
    }
}

/**
 * @param array<string,mixed> $meta
 */
function setDriverOfferStatus($redis, int $requestId, int $driverId, string $status, array $meta = [], int $ttl = DRIVER_STATUS_TTL_SEC): void
{
    $normalized = strtolower(trim($status));
    if ($normalized === '') {
        $normalized = 'pending';
    }

    $baseKey = 'ride:' . $requestId . ':driver:' . $driverId . ':status';
    $redis->setex($baseKey, $ttl, $normalized);

    if (!empty($meta)) {
        $payload = $meta;
        $payload['status'] = $normalized;
        $payload['driver_id'] = $driverId;
        $payload['updated_at'] = function_exists('now_colombia')
            ? now_colombia()->format('c')
            : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c');
        $redis->setex($baseKey . ':meta', $ttl, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}

function clearCurrentDriverIfMatches($redis, int $requestId, int $driverId): void
{
    $currentRaw = $redis->get('ride:' . $requestId . ':current_driver');
    if (is_string($currentRaw) && (int)$currentRaw === $driverId) {
        $redis->del('ride:' . $requestId . ':current_driver');
    }
}

function sendTripRequestToDriver($redis, int $driverId, int $requestId, string $offeredAt): void
{
    $payload = [
        'trip_id' => $requestId,
        'driver_id' => $driverId,
        'offered_at' => $offeredAt,
        'timeout_sec' => OFFER_TIMEOUT_SEC,
    ];
    $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $redis->publish('trip:offers:' . $requestId, $serialized);

    // Cola auxiliar por conductor para consumidores que no usan Pub/Sub.
    $driverQueueKey = 'driver:' . $driverId . ':trip_offers';
    $redis->lPush($driverQueueKey, $serialized);
    $redis->lTrim($driverQueueKey, 0, 19);
    $redis->expire($driverQueueKey, 120);

    // Publicar al gateway WebSocket para entrega inmediata al conductor
    RealtimeEventPublisher::tripOfferSent($requestId, $driverId, [
        'offered_at' => $offeredAt,
        'timeout_sec' => OFFER_TIMEOUT_SEC,
    ]);
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

    $redis->expire($lockKey, OFFER_TIMEOUT_SEC + 2);

    $redis->sAdd($offeredSetKey, (string)$driverId);
    $redis->expire($offeredSetKey, 600);

    $redis->incr('metrics:offers_sent');
    $offeredAt = function_exists('now_colombia')
        ? now_colombia()->format('c')
        : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c');
    $redis->setex('ride:' . $requestId . ':offer_ts:' . $driverId, 120, (string)nowMs());
    $redis->setex('ride:' . $requestId . ':current_driver', CURRENT_DRIVER_TTL_SEC, (string)$driverId);
    setDriverOfferStatus($redis, $requestId, $driverId, 'pending', [
        'offered_at' => $offeredAt,
        'timeout_sec' => OFFER_TIMEOUT_SEC,
        'ui_rotation_sec' => UI_ROTATION_SEC,
    ], DRIVER_STATUS_TTL_SEC);
    setMatchingStatus($redis, $requestId, 'checking', 120);

    // Compatibilidad legacy para clientes que aun leen current_offer.
    Cache::set('ride:' . $requestId . ':current_offer', (string)json_encode([
        'driver_id' => $driverId,
        'offered_at' => $offeredAt,
        'timeout_sec' => OFFER_TIMEOUT_SEC,
    ], JSON_UNESCAPED_UNICODE), 30);

    sendTripRequestToDriver($redis, $driverId, $requestId, $offeredAt);

    return true;
}

function tryAcquireTripLock($redis, int $tripId, int $driverId): bool
{
    $lockKey = 'trip_lock:' . $tripId;
    $existing = $redis->get($lockKey);
    if (is_string($existing) && (int)$existing === $driverId) {
        $redis->expire($lockKey, 120);
        return true;
    }

    try {
        $ok = $redis->set($lockKey, (string)$driverId, ['NX', 'EX' => 120]);
        if ($ok === true || strtoupper((string)$ok) === 'OK') {
            return true;
        }
    } catch (Throwable $e) {
        $ok = $redis->setnx($lockKey, (string)$driverId);
        if ($ok) {
            $redis->expire($lockKey, 120);
            return true;
        }
    }

    $owner = $redis->get($lockKey);
    if (is_string($owner) && (int)$owner === $driverId) {
        $redis->expire($lockKey, 120);
        return true;
    }

    return false;
}

/**
 * @return array{accepted:bool,rejected:bool,accept_time_ms:int,status:string}
 */
function waitForAcceptance(PDO $db, $redis, int $requestId, int $driverId, int $timeoutSec = OFFER_TIMEOUT_SEC): array
{
    $deadline = time() + $timeoutSec;
    $responseQueueKey = 'trip:responses_queue:' . $requestId;
    $offerTsRaw = $redis->get('ride:' . $requestId . ':offer_ts:' . $driverId);
    $offerTsMs = is_string($offerTsRaw) && is_numeric($offerTsRaw) ? (int)$offerTsRaw : nowMs();

    while (time() < $deadline) {
        refreshTripProcessLock($redis, $requestId);

        $tripLockOwnerRaw = $redis->get('trip_lock:' . $requestId);
        if (is_string($tripLockOwnerRaw) && (int)$tripLockOwnerRaw > 0 && (int)$tripLockOwnerRaw !== $driverId) {
            return [
                'accepted' => false,
                'rejected' => true,
                'accept_time_ms' => max(0, nowMs() - $offerTsMs),
                'status' => 'accepted_other',
            ];
        }

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
                    'status' => 'rejected',
                ];
            }

            if ($responseDriverId === $driverId && in_array($statusRaw, ['accepted', 'accept'], true) && tryAcquireTripLock($redis, $requestId, $driverId)) {
                return [
                    'accepted' => true,
                    'rejected' => false,
                    'accept_time_ms' => max(0, nowMs() - $offerTsMs),
                    'status' => 'accepted',
                ];
            }
        }

        $acceptedRaw = Cache::get('ride:' . $requestId . ':accepted_driver');
        if (is_string($acceptedRaw) && intval($acceptedRaw) === $driverId && tryAcquireTripLock($redis, $requestId, $driverId)) {
            return [
                'accepted' => true,
                'rejected' => false,
                'accept_time_ms' => max(0, nowMs() - $offerTsMs),
                'status' => 'accepted',
            ];
        }

        $stmt = $db->prepare('SELECT estado FROM solicitudes_servicio WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $requestId]);
        $estado = strtolower((string)($stmt->fetchColumn() ?: ''));
        if (in_array($estado, ['aceptada', 'asignado', 'driver_assigned'], true)) {
            if (!tryAcquireTripLock($redis, $requestId, $driverId)) {
                return [
                    'accepted' => false,
                    'rejected' => true,
                    'accept_time_ms' => max(0, nowMs() - $offerTsMs),
                    'status' => 'accepted_other',
                ];
            }
            return [
                'accepted' => true,
                'rejected' => false,
                'accept_time_ms' => max(0, nowMs() - $offerTsMs),
                'status' => 'accepted',
            ];
        }

        usleep(250000);
    }

    $redis->incr('metrics:dispatch_timeouts');
    return [
        'accepted' => false,
        'rejected' => false,
        'accept_time_ms' => max(0, nowMs() - $offerTsMs),
        'status' => 'timeout',
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

        if (!acquireTripProcessLock($redis, $requestId)) {
            continue;
        }

        try {
        $dispatchStartMs = nowMs();
        $accepted = false;
        $driverAcceptedId = null;
        $offerCursor = 0;
        $offersSentForTrip = 0;
        $driversOffered = 0;
        $dispatchDeadline = time() + TRIP_CANCEL_TIMEOUT_SEC;
        $cityId = DriverGeoService::getCityIdFromCoordinates((float)$ride['latitud_recogida'], (float)$ride['longitud_recogida']);

        setMatchingStatus($redis, $requestId, 'searching', 120);

        $searchStartMs = nowMs();
        $searchRadiusKm = MATCH_RADIUS_KM_INITIAL;
        $rankedAll = buildRankedDrivers($db, $ride, $searchRadiusKm);
        $searchRetried = false;
        $searchLatencyMs = max(0, nowMs() - $searchStartMs);
        $redis->incrBy('metrics:driver_search_latency', $searchLatencyMs);
        $redis->incr('metrics:driver_search_latency_count');
        if ($searchLatencyMs > DRIVER_SEARCH_TARGET_MS) {
            $redis->incr('metrics:driver_search_sla_breach');
        }

        if (empty($rankedAll)) {
            // Retry inteligente: ampliar radio una vez.
            $searchRetried = true;
            $searchRadiusKm = $searchRadiusKm * MATCH_RADIUS_RETRY_MULTIPLIER;
            setMatchingStatus($redis, $requestId, 'expanding_search', 120);
            $rankedAll = RideMatchingService::rankCandidates(
                $db,
                (float)$ride['latitud_recogida'],
                (float)$ride['longitud_recogida'],
                $searchRadiusKm,
                30,
                (string)($ride['tipo_vehiculo'] ?? 'moto'),
                isset($ride['empresa_id']) ? (int)$ride['empresa_id'] : null
            );
        }

        if (!empty($rankedAll)) {
            setMatchingStatus($redis, $requestId, $searchRetried ? 'search_expanded' : 'searching', 120);
        }

        if (empty($rankedAll)) {
            $redis->incr('metrics:dispatch_no_drivers');
            $redis->del('ride:' . $requestId . ':current_driver');
            markNoDriversAvailable($db, $redis, $requestId);
            writeDispatchLog([
                'trip_id' => $requestId,
                'drivers_found' => 0,
                'drivers_offered' => 0,
                'driver_accepted' => null,
                'search_time_ms' => $searchLatencyMs,
                'drivers_attempted' => 0,
                'accepted' => false,
                'dispatch_time_ms' => max(0, nowMs() - $dispatchStartMs),
                'result' => 'sin_conductores',
            ]);
            continue;
        }

        $driversFound = count($rankedAll);
        $redis->incrBy('metrics:drivers_scanned', $driversFound);

        $driverIds = array_values(array_map(static fn($x) => (int)$x['driver_id'], $rankedAll));
        $driversQueuePayload = json_encode($driverIds, JSON_UNESCAPED_UNICODE);
        $redis->setex('ride:' . $requestId . ':drivers', 600, $driversQueuePayload);
        $redis->setex('ride:' . $requestId . ':drivers_queue', 600, $driversQueuePayload);
        $redis->expire('trip:offered_drivers:' . $requestId, 600);

        while (!$accepted && time() < $dispatchDeadline && $offerCursor < count($rankedAll) && $offersSentForTrip < MAX_OFFERS_PER_TRIP) {
            refreshTripProcessLock($redis, $requestId);
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
                writeDispatchLog([
                    'trip_id' => $requestId,
                    'driver_attempted' => $driverId,
                    'result' => 'sent',
                ]);
            }

            foreach ($offeredDriverIds as $driverId) {
                refreshTripProcessLock($redis, $requestId);
                $acceptResult = waitForAcceptance($db, $redis, $requestId, $driverId);
                $attemptStatus = (string)($acceptResult['status'] ?? 'timeout');

                writeDispatchLog([
                    'trip_id' => $requestId,
                    'driver_attempted' => $driverId,
                    'result' => $attemptStatus,
                    'accept_time_ms' => max(0, (int)$acceptResult['accept_time_ms']),
                ]);

                if ($acceptResult['accepted']) {
                    setDriverOfferStatus($redis, $requestId, $driverId, 'accepted', [
                        'accept_time_ms' => max(0, (int)$acceptResult['accept_time_ms']),
                    ], DRIVER_STATUS_FINAL_TTL_SEC);
                    $redis->setex('ride:' . $requestId . ':current_driver', CURRENT_DRIVER_TTL_SEC, (string)$driverId);
                    setMatchingStatus($redis, $requestId, 'matched', 180);
                    markAssignedCache($requestId, $driverId);
                    $redis->incr('metrics:acceptance_rate');
                    $redis->incrBy('metrics:driver_accept_time', max(0, (int)$acceptResult['accept_time_ms']));
                    $redis->incr('metrics:driver_accept_time_count');
                    $accepted = true;
                    $driverAcceptedId = $driverId;
                    break;
                }

                setDriverOfferStatus($redis, $requestId, $driverId, $attemptStatus, [
                    'accept_time_ms' => max(0, (int)$acceptResult['accept_time_ms']),
                ], DRIVER_STATUS_FINAL_TTL_SEC);
                clearCurrentDriverIfMatches($redis, $requestId, $driverId);
                $redis->del('driver_offer_lock:' . $driverId);
                setDriverCooldown($redis, $driverId);
                DriverGeoService::updateDriverStats($driverId, null, 0.15, null);

                if ($attemptStatus === 'timeout') {
                    $redis->incr('metrics:dispatch_driver_timeout');
                } elseif ($attemptStatus === 'rejected') {
                    $redis->incr('metrics:dispatch_driver_reject');
                }
            }

            if ($accepted) {
                break;
            }

            if (empty($offeredDriverIds)) {
                usleep(200000);
                continue;
            }

            // Evitar busy loop sin agregar latencias artificiales largas.
            usleep(150000);
        }

        if (!$accepted && $offersSentForTrip >= MAX_OFFERS_PER_TRIP) {
            $redis->incr('metrics:dispatch_cancelled_max_offers');
            $redis->del('ride:' . $requestId . ':current_driver');
            setMatchingStatus($redis, $requestId, 'exhausted', 180);
            cancelRideRequest($db, $redis, $requestId, 'max_offers_exceeded');
        }

        if (!$accepted && time() >= $dispatchDeadline) {
            $redis->incr('metrics:dispatch_expired');
            $redis->del('ride:' . $requestId . ':current_driver');
            setMatchingStatus($redis, $requestId, 'timeout', 180);
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
            'search_time_ms' => $searchLatencyMs,
            'drivers_found' => $driversFound,
            'drivers_attempted' => $driversOffered,
            'drivers_offered' => $driversOffered,
            'driver_accepted' => $driverAcceptedId,
            'accepted' => $accepted,
            'dispatch_time_ms' => $dispatchMs,
        ]);
        } finally {
            releaseTripProcessLock($redis, $requestId);
        }
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
