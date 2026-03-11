<?php
/**
 * Dispatch worker de ofertas de conductores (modelo Uber-grade liviano).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/matching_service.php';
require_once __DIR__ . '/../services/driver_service.php';
require_once __DIR__ . '/../services/trip_state_machine.php';

const DISPATCH_QUEUE_KEY = 'ride_requests_queue';
const OFFER_TIMEOUT_SEC = 10;

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
    return RideMatchingService::rankCandidates(
        $db,
        (float)$ride['latitud_recogida'],
        (float)$ride['longitud_recogida'],
        $radiusKm,
        20,
        (string)($ride['tipo_vehiculo'] ?? 'moto'),
        isset($ride['empresa_id']) ? (int)$ride['empresa_id'] : null
    );
}

function tryOfferDriver($redis, int $requestId, int $driverId): bool
{
    $lockKey = 'driver:' . $driverId . ':offer_lock';
    $locked = $redis->setnx($lockKey, (string)$requestId);
    if (!$locked) {
        return false;
    }

    $redis->expire($lockKey, 15);
    $redis->incr('metrics:dispatch_attempts');

    Cache::set('ride:' . $requestId . ':current_offer', (string)json_encode([
        'driver_id' => $driverId,
        'offered_at' => gmdate('c'),
        'timeout_sec' => OFFER_TIMEOUT_SEC,
    ], JSON_UNESCAPED_UNICODE), 30);

    return true;
}

function waitForAcceptance(PDO $db, $redis, int $requestId, int $driverId): bool
{
    $deadline = time() + OFFER_TIMEOUT_SEC;

    while (time() < $deadline) {
        $acceptedRaw = Cache::get('ride:' . $requestId . ':accepted_driver');
        if (is_string($acceptedRaw) && intval($acceptedRaw) === $driverId) {
            return true;
        }

        $stmt = $db->prepare('SELECT estado FROM solicitudes_servicio WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $requestId]);
        $estado = strtolower((string)($stmt->fetchColumn() ?: ''));
        if (in_array($estado, ['aceptada', 'asignado', 'driver_assigned'], true)) {
            return true;
        }

        usleep(250000);
    }

    $redis->incr('metrics:dispatch_timeouts');
    return false;
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
        $entry = $redis->brPop([DISPATCH_QUEUE_KEY], 3);
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

        $dispatchStart = microtime(true);
        $accepted = false;

        foreach ([2.0, 4.0, 6.0, 8.0, 10.0] as $radiusKm) {
            $redis->setex('ride:' . $requestId . ':radius', 600, (string)$radiusKm);
            $ranked = buildRankedDrivers($db, $ride, $radiusKm);

            $driverIds = array_values(array_map(static fn($x) => (int)$x['driver_id'], $ranked));
            $redis->setex('ride:' . $requestId . ':drivers', 600, json_encode($driverIds, JSON_UNESCAPED_UNICODE));

            if (empty($ranked)) {
                continue;
            }

            foreach ($ranked as $candidate) {
                $driverId = (int)$candidate['driver_id'];
                if ($driverId <= 0) {
                    continue;
                }

                if (!tryOfferDriver($redis, $requestId, $driverId)) {
                    continue;
                }

                if (waitForAcceptance($db, $redis, $requestId, $driverId)) {
                    markAssignedCache($requestId, $driverId);
                    $accepted = true;
                    break;
                }

                $redis->del('driver:' . $driverId . ':offer_lock');
                DriverGeoService::updateDriverStats($driverId, null, 0.15, null);
            }

            if ($accepted) {
                break;
            }
        }

        $latencyMs = (int)round((microtime(true) - $dispatchStart) * 1000);
        $redis->incrBy('metrics:dispatch_latency', max(0, $latencyMs));
        $redis->incr('metrics:dispatch_latency_count');
    } catch (Throwable $e) {
        error_log('[dispatch_worker] ' . $e->getMessage());
        usleep(300000);
    }
}
