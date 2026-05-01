<?php
/**
 * Worker asíncrono de dispatch con ofertas por lotes (nivel producción).
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
const TRIP_CANCEL_TIMEOUT_SEC = 180;
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
const DISPATCH_RECOVERY_WINDOW_SEC = 300;
const REJECTED_DRIVERS_TTL_SEC = 600;
const REJECTED_OVERRIDE_AFTER_SEC = 150;
const ACTIVE_RIDE_TTL_SEC = 14400;
const RIDE_LOCK_KEY_PREFIX = 'lock:ride:';
const DRIVERS_VIEWING_TTL_SEC = 120;
const SEARCH_RADIUS_MAX_KM = 10.0;

function nowMs(): int
{
    return (int)round(microtime(true) * 1000);
}

function currentWorkerId(): string
{
    static $workerId = null;
    if ($workerId === null) {
        $host = gethostname();
        if (!is_string($host) || trim($host) === '') {
            $host = 'dispatch-worker';
        }
        $pid = getmypid();
        if (!is_int($pid) || $pid <= 0) {
            $pid = random_int(1000, 9999);
        }
        $workerId = $host . ':' . $pid;
    }

    return $workerId;
}

function rideCreatedAtTs(array $ride): int
{
    $raw = (string)($ride['created_at'] ?? '');
    $ts = strtotime($raw);
    if ($ts === false) {
        return time();
    }

    return (int)$ts;
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
        "SELECT id, estado, cliente_id, tipo_vehiculo, empresa_id, latitud_recogida, longitud_recogida,
                COALESCE(solicitado_en, fecha_creacion) AS created_at
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
    if (in_array($estado, ['pendiente', 'requested'], true)) {
        return true;
    }

    if (in_array($estado, ['sin_conductores', 'timeout', 'exhausted'], true)) {
        $ageSec = max(0, time() - rideCreatedAtTs($ride));
        return $ageSec <= DISPATCH_RECOVERY_WINDOW_SEC;
    }

    return false;
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

function rejectedDriversKey(int $requestId): string
{
    return 'ride:' . $requestId . ':rejected_drivers';
}

function addRejectedDriverForRide($redis, int $requestId, int $driverId, int $ttl = REJECTED_DRIVERS_TTL_SEC): void
{
    if ($driverId <= 0 || $requestId <= 0) {
        return;
    }

    $key = rejectedDriversKey($requestId);
    $redis->sAdd($key, (string)$driverId);
    $redis->expire($key, $ttl);
    Cache::set('trip_rejected:' . $requestId . ':' . $driverId, '1', $ttl);
}

function driversViewingListKey(int $requestId): string
{
    return 'ride:' . $requestId . ':drivers_viewing';
}

function driverViewingPayloadKey(int $requestId, int $driverId): string
{
    return 'ride:' . $requestId . ':drivers_viewing:driver:' . $driverId;
}

function normalizeDriverNameFromCandidate(array $candidate): string
{
    $nombre = trim((string)($candidate['nombre'] ?? ''));
    $apellido = trim((string)($candidate['apellido'] ?? ''));
    $full = trim($nombre . ' ' . $apellido);
    return $full !== '' ? $full : 'Conductor';
}

function formatEtaLabel(?int $etaMinutes): string
{
    if (!is_int($etaMinutes) || $etaMinutes <= 0) {
        return 'N/A';
    }

    return $etaMinutes . ' min';
}

/**
 * @return array<string,mixed>|null
 */
function buildDriverViewingPayload(array $candidate): ?array
{
    $driverId = (int)($candidate['driver_id'] ?? $candidate['id'] ?? 0);
    if ($driverId <= 0) {
        return null;
    }

    $distanceKm = (float)($candidate['distance_km'] ?? $candidate['driver_distance'] ?? 0.0);
    $etaMinutes = isset($candidate['eta_minutes']) ? (int)$candidate['eta_minutes'] : null;
    $rating = isset($candidate['rating'])
        ? (float)$candidate['rating']
        : (isset($candidate['calificacion_promedio']) ? (float)$candidate['calificacion_promedio'] : 0.0);

    return [
        'driver_id' => $driverId,
        'name' => normalizeDriverNameFromCandidate($candidate),
        'rating' => round($rating, 1),
        'eta' => formatEtaLabel($etaMinutes),
        'eta_minutes' => $etaMinutes,
        'distance_km' => round(max(0.0, $distanceKm), 2),
        'photo' => (string)($candidate['foto_perfil'] ?? ''),
        'updated_at' => function_exists('now_colombia')
            ? now_colombia()->format('c')
            : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c'),
    ];
}

function upsertDriverViewing($redis, int $requestId, array $candidate, int $ttl = DRIVERS_VIEWING_TTL_SEC): void
{
    $payload = buildDriverViewingPayload($candidate);
    if ($payload === null) {
        return;
    }

    $driverId = (int)$payload['driver_id'];
    if ($driverId <= 0) {
        return;
    }

    $listKey = driversViewingListKey($requestId);
    $payloadKey = driverViewingPayloadKey($requestId, $driverId);
    $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($serialized) || $serialized === '') {
        return;
    }

    $previous = $redis->get($payloadKey);
    if (is_string($previous) && $previous !== '') {
        $redis->lRem($listKey, 0, $previous);
    }

    $redis->lPush($listKey, $serialized);
    $redis->lTrim($listKey, 0, 9);
    $redis->expire($listKey, $ttl);
    $redis->setex($payloadKey, $ttl, $serialized);
}

function removeDriverViewing($redis, int $requestId, int $driverId): void
{
    if ($requestId <= 0 || $driverId <= 0) {
        return;
    }

    $listKey = driversViewingListKey($requestId);
    $payloadKey = driverViewingPayloadKey($requestId, $driverId);

    $previous = $redis->get($payloadKey);
    if (is_string($previous) && $previous !== '') {
        $redis->lRem($listKey, 0, $previous);
    }

    $entries = $redis->lRange($listKey, 0, 20);
    if (is_array($entries)) {
        foreach ($entries as $raw) {
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            if ((int)($decoded['driver_id'] ?? 0) === $driverId) {
                $redis->lRem($listKey, 0, $raw);
            }
        }
    }

    $redis->del($payloadKey);
}

function clearDriversViewingList($redis, int $requestId): void
{
    if ($requestId <= 0) {
        return;
    }

    $redis->del(driversViewingListKey($requestId));
}

function setSearchRadiusCache($redis, int $requestId, float $radiusKm, float $maxRadiusKm = SEARCH_RADIUS_MAX_KM, int $ttl = 180): void
{
    if ($requestId <= 0) {
        return;
    }

    $redis->setex('ride:' . $requestId . ':search_radius_km', $ttl, (string)round(max(0.0, $radiusKm), 2));
    $redis->setex('ride:' . $requestId . ':max_radius_km', $ttl, (string)round(max(1.0, $maxRadiusKm), 2));
}

function setUiMessage($redis, int $requestId, string $message, int $ttl = 120): void
{
    if ($requestId <= 0) {
        return;
    }

    $normalized = trim($message);
    if ($normalized === '') {
        return;
    }

    $redis->setex('ride:' . $requestId . ':ui_message', $ttl, $normalized);
}

function isDriverRejectedForRide($redis, int $requestId, int $driverId): bool
{
    if ($requestId <= 0 || $driverId <= 0) {
        return false;
    }

    return (bool)$redis->sIsMember(rejectedDriversKey($requestId), (string)$driverId);
}

function isDriverBusyWithOtherRide($redis, int $requestId, int $driverId): bool
{
    if ($driverId <= 0) {
        return true;
    }

    $activeRideRaw = $redis->get('driver:' . $driverId . ':active_ride');
    if (!is_string($activeRideRaw) || trim($activeRideRaw) === '') {
        return false;
    }

    $activeRideId = (int)$activeRideRaw;
    return $activeRideId > 0 && $activeRideId !== $requestId;
}

function shouldIgnoreRejectedDrivers(array $ride): bool
{
    $ageSec = max(0, time() - rideCreatedAtTs($ride));
    return $ageSec >= REJECTED_OVERRIDE_AFTER_SEC;
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
               AND LOWER(estado) IN ('pendiente', 'requested', 'sin_conductores', 'timeout', 'exhausted')"
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
    $lockKey = RIDE_LOCK_KEY_PREFIX . $tripId;
    $legacyLockKey = 'ride:' . $tripId . ':lock';
    $workerId = currentWorkerId();
    try {
        $ok = $redis->set($lockKey, $workerId, ['NX', 'EX' => $ttl]);
        $acquired = $ok === true || strtoupper((string)$ok) === 'OK';
        if ($acquired) {
            $redis->setex($legacyLockKey, $ttl, $workerId);
        }
        return $acquired;
    } catch (Throwable $e) {
        $ok = $redis->setnx($lockKey, $workerId);
        if ($ok) {
            $redis->expire($lockKey, $ttl);
            $redis->setex($legacyLockKey, $ttl, $workerId);
        }
        return (bool)$ok;
    }
}

function refreshTripProcessLock($redis, int $tripId, int $ttl = TRIP_PROCESS_LOCK_TTL_SEC): void
{
    try {
        $redis->expire(RIDE_LOCK_KEY_PREFIX . $tripId, $ttl);
        $redis->expire('ride:' . $tripId . ':lock', $ttl);
    } catch (Throwable $e) {
    }
}

function releaseTripProcessLock($redis, int $tripId): void
{
    try {
        $lockKey = RIDE_LOCK_KEY_PREFIX . $tripId;
        $legacyLockKey = 'ride:' . $tripId . ':lock';
        $workerId = currentWorkerId();
        $ownerRaw = $redis->get($lockKey);
        if (is_string($ownerRaw) && $ownerRaw === $workerId) {
            $redis->del($lockKey);
            $redis->del($legacyLockKey);
        }
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

function tryOfferDriver($redis, int $requestId, int $driverId, bool $ignoreRejectedDrivers = false, ?array $candidateMeta = null): bool
{
    if (isDriverBusyWithOtherRide($redis, $requestId, $driverId)) {
        return false;
    }

    $rejectedDrivers = $redis->sMembers(rejectedDriversKey($requestId));
    if (!is_array($rejectedDrivers)) {
        $rejectedDrivers = [];
    }

    $rejectedAsStrings = array_map(static fn($value) => (string)$value, $rejectedDrivers);
    $isRejectedDriver = in_array((string)$driverId, $rejectedAsStrings, true);

    error_log('REJECTED_DRIVERS ride ' . $requestId . ': ' . json_encode(array_values($rejectedAsStrings), JSON_UNESCAPED_UNICODE));
    error_log('DRIVER ' . $driverId . ' filtrado: ' . ($isRejectedDriver ? 'SI' : 'NO'));

    if (!$ignoreRejectedDrivers && $isRejectedDriver) {
        return false;
    }

    if ($redis->exists('driver:cooldown:' . $driverId)) {
        return false;
    }

    $offeredSetKey = 'trip:offered_drivers:' . $requestId;
    $alreadyOffered = $redis->sIsMember($offeredSetKey, (string)$driverId);
    if ($alreadyOffered && !$ignoreRejectedDrivers) {
        return false;
    }

    if ($alreadyOffered && $ignoreRejectedDrivers) {
        $fallbackRetryGateKey = 'ride:' . $requestId . ':fallback_retry_gate:' . $driverId;
        if ($redis->exists($fallbackRetryGateKey)) {
            return false;
        }
        $redis->setex($fallbackRetryGateKey, 45, '1');
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

    if (is_array($candidateMeta)) {
        upsertDriverViewing($redis, $requestId, $candidateMeta);
        $driverName = normalizeDriverNameFromCandidate($candidateMeta);
        setUiMessage($redis, $requestId, 'Enviando solicitud a ' . $driverName);
    }

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
        $assignedElsewhere = false;
        $driverAcceptedId = null;
        $offerCursor = 0;
        $offersSentForTrip = 0;
        $driversOffered = 0;
        $driversFound = 0;
        $offeredDriverMeta = [];
        $dispatchDeadline = time() + TRIP_CANCEL_TIMEOUT_SEC;
        $cityId = DriverGeoService::getCityIdFromCoordinates((float)$ride['latitud_recogida'], (float)$ride['longitud_recogida']);
        $searchLatencyTotalMs = 0;
        $searchAttempts = 0;

        // Reabrir estados recuperables recientes sin romper asignaciones existentes.
        $estadoRide = strtolower(trim((string)($ride['estado'] ?? '')));
        if (in_array($estadoRide, ['sin_conductores', 'timeout', 'exhausted'], true)) {
            $stmtResume = $db->prepare(
                "UPDATE solicitudes_servicio
                 SET estado = 'pendiente'
                 WHERE id = :id
                   AND LOWER(estado) IN ('sin_conductores', 'timeout', 'exhausted')
                   AND NOT EXISTS (
                       SELECT 1 FROM asignaciones_conductor ac WHERE ac.solicitud_id = :id
                   )"
            );
            $stmtResume->execute([':id' => $requestId]);
            $ride['estado'] = 'pendiente';
        }

        $searchRadiusKm = MATCH_RADIUS_KM_INITIAL;
        setMatchingStatus($redis, $requestId, 'searching', 120);
        setSearchRadiusCache($redis, $requestId, $searchRadiusKm, SEARCH_RADIUS_MAX_KM, 180);
        setUiMessage($redis, $requestId, 'Buscando conductores cercanos', 120);

        $searchStartMs = nowMs();
        $rankedAll = buildRankedDrivers($db, $ride, $searchRadiusKm);
        $searchRetried = false;
        $searchLatencyMs = max(0, nowMs() - $searchStartMs);
        $searchLatencyTotalMs += $searchLatencyMs;
        $searchAttempts++;
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
            setSearchRadiusCache($redis, $requestId, $searchRadiusKm, SEARCH_RADIUS_MAX_KM, 180);
            setUiMessage($redis, $requestId, 'Ampliando búsqueda de conductores', 120);
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
            $driversFound = max($driversFound, count($rankedAll));
        }

        if ($driversFound > 0) {
            $redis->incrBy('metrics:drivers_scanned', $driversFound);
        }

        $driverIds = array_values(array_map(static fn($x) => (int)$x['driver_id'], $rankedAll));
        $driversQueuePayload = json_encode($driverIds, JSON_UNESCAPED_UNICODE);
        $redis->setex('ride:' . $requestId . ':drivers', 600, $driversQueuePayload);
        $redis->setex('ride:' . $requestId . ':drivers_queue', 600, $driversQueuePayload);
        $redis->expire('trip:offered_drivers:' . $requestId, 600);

        while (!$accepted && time() < $dispatchDeadline && $offersSentForTrip < MAX_OFFERS_PER_TRIP) {
            refreshTripProcessLock($redis, $requestId);
            $ignoreRejectedDrivers = shouldIgnoreRejectedDrivers($ride);

            if ($offerCursor >= count($rankedAll)) {
                $retryRadiusKm = $ignoreRejectedDrivers
                    ? ($searchRadiusKm * MATCH_RADIUS_RETRY_MULTIPLIER)
                    : $searchRadiusKm;

                $searchStartMs = nowMs();
                $rankedAll = buildRankedDrivers($db, $ride, $retryRadiusKm);
                $searchLatencyMs = max(0, nowMs() - $searchStartMs);
                $searchLatencyTotalMs += $searchLatencyMs;
                $searchAttempts++;
                $redis->incrBy('metrics:driver_search_latency', $searchLatencyMs);
                $redis->incr('metrics:driver_search_latency_count');
                if ($searchLatencyMs > DRIVER_SEARCH_TARGET_MS) {
                    $redis->incr('metrics:driver_search_sla_breach');
                }

                $searchRadiusKm = $retryRadiusKm;
                setSearchRadiusCache($redis, $requestId, $searchRadiusKm, SEARCH_RADIUS_MAX_KM, 180);
                $offerCursor = 0;
                if (empty($rankedAll)) {
                    setMatchingStatus($redis, $requestId, 'expanding_search', 120);
                    setSearchRadiusCache($redis, $requestId, $searchRadiusKm, SEARCH_RADIUS_MAX_KM, 180);
                    setUiMessage($redis, $requestId, 'Ampliando búsqueda de conductores', 120);
                    usleep(700000);
                    continue;
                }

                $driversFound = max($driversFound, count($rankedAll));
                $redis->incrBy('metrics:drivers_scanned', count($rankedAll));
                setMatchingStatus($redis, $requestId, $ignoreRejectedDrivers ? 'search_expanded' : 'searching', 120);
            }

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

                if (!tryOfferDriver($redis, $requestId, $driverId, $ignoreRejectedDrivers, $candidate)) {
                    continue;
                }

                $offeredDriverIds[] = $driverId;
                $offeredDriverMeta[$driverId] = $candidate;
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
                    clearDriversViewingList($redis, $requestId);
                    setUiMessage($redis, $requestId, 'Conductor asignado', 120);
                    markAssignedCache($requestId, $driverId);
                    $redis->incr('metrics:acceptance_rate');
                    $redis->incrBy('metrics:driver_accept_time', max(0, (int)$acceptResult['accept_time_ms']));
                    $redis->incr('metrics:driver_accept_time_count');
                    $accepted = true;
                    $driverAcceptedId = $driverId;
                    $redis->setex('driver:' . $driverId . ':active_ride', ACTIVE_RIDE_TTL_SEC, (string)$requestId);
                    break;
                }

                setDriverOfferStatus($redis, $requestId, $driverId, $attemptStatus, [
                    'accept_time_ms' => max(0, (int)$acceptResult['accept_time_ms']),
                ], DRIVER_STATUS_FINAL_TTL_SEC);
                removeDriverViewing($redis, $requestId, $driverId);
                clearCurrentDriverIfMatches($redis, $requestId, $driverId);
                $redis->del('driver_offer_lock:' . $driverId);
                setDriverCooldown($redis, $driverId);
                DriverGeoService::updateDriverStats($driverId, null, 0.15, null);

                if ($attemptStatus === 'rejected') {
                    $candidateMeta = $offeredDriverMeta[$driverId] ?? [];
                    $driverName = normalizeDriverNameFromCandidate(is_array($candidateMeta) ? $candidateMeta : []);
                    setUiMessage($redis, $requestId, $driverName . ' no pudo aceptar el viaje', 120);
                }

                if ($attemptStatus === 'accepted_other') {
                    $assignedElsewhere = true;
                    break;
                }

                if ($attemptStatus === 'timeout' || $attemptStatus === 'rejected') {
                    addRejectedDriverForRide($redis, $requestId, $driverId);
                }

                if ($attemptStatus === 'timeout') {
                    $redis->incr('metrics:dispatch_driver_timeout');
                } elseif ($attemptStatus === 'rejected') {
                    $redis->incr('metrics:dispatch_driver_reject');
                }
            }

            if ($accepted) {
                break;
            }

            if ($assignedElsewhere) {
                break;
            }

            if (empty($offeredDriverIds)) {
                usleep(200000);
                continue;
            }

            // Evitar busy loop sin agregar latencias artificiales largas.
            usleep(150000);
        }

        if (!$accepted && !$assignedElsewhere) {
            if ($offersSentForTrip >= MAX_OFFERS_PER_TRIP) {
                $redis->incr('metrics:dispatch_cancelled_max_offers');
                setMatchingStatus($redis, $requestId, 'exhausted', 180);
            } elseif (time() >= $dispatchDeadline) {
                $redis->incr('metrics:dispatch_expired');
                setMatchingStatus($redis, $requestId, 'timeout', 180);
            } else {
                setMatchingStatus($redis, $requestId, 'sin_conductores', 180);
            }

            $redis->incr('metrics:dispatch_no_drivers');
            $redis->del('ride:' . $requestId . ':current_driver');
            clearDriversViewingList($redis, $requestId);
            setUiMessage($redis, $requestId, 'No hay conductores disponibles por ahora', 180);
            markNoDriversAvailable($db, $redis, $requestId);
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
            'search_time_ms' => $searchAttempts > 0 ? (int)round($searchLatencyTotalMs / $searchAttempts) : 0,
            'drivers_found' => $driversFound,
            'drivers_attempted' => $driversOffered,
            'drivers_offered' => $driversOffered,
            'driver_accepted' => $driverAcceptedId,
            'accepted' => $accepted,
            'assigned_elsewhere' => $assignedElsewhere,
            'dispatch_time_ms' => $dispatchMs,
            'result' => $accepted ? 'accepted' : ($assignedElsewhere ? 'accepted_other' : 'sin_conductores'),
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
