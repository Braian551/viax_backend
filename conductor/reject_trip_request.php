<?php
header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../config/redis.php';
require_once '../core/Cache.php';
require_once '../services/driver_service.php';
require_once __DIR__ . '/driver_auth.php';

const RIDE_REJECTED_TTL_SEC = 600;
const REQUEUE_AFTER_REJECT_TTL_SEC = 3;
const RIDE_VIEWING_TTL_SEC = 120;

function isMissingRechazosTableError(Throwable $e): bool
{
    $message = strtolower($e->getMessage());
    if (strpos($message, 'rechazos_conductor') === false) {
        return false;
    }

    return strpos($message, "doesn't exist") !== false
        || strpos($message, 'does not exist') !== false
        || strpos($message, 'undefined table') !== false
        || strpos($message, 'relation') !== false;
}

function rememberDriverRejectedForRide($redis, int $solicitudId, int $conductorId): void
{
    $rideRejectedKey = 'ride:' . $solicitudId . ':rejected_drivers';
    $redis->sAdd($rideRejectedKey, (string)$conductorId);
    $redis->expire($rideRejectedKey, RIDE_REJECTED_TTL_SEC);

    $rejectedDrivers = $redis->sMembers($rideRejectedKey);
    if (!is_array($rejectedDrivers)) {
        $rejectedDrivers = [];
    }

    $rejectedAsStrings = array_map(static fn($value) => (string)$value, $rejectedDrivers);
    $isFiltered = in_array((string)$conductorId, $rejectedAsStrings, true);

    error_log('REJECTED_DRIVERS ride ' . $solicitudId . ': ' . json_encode(array_values($rejectedAsStrings), JSON_UNESCAPED_UNICODE));
    error_log('DRIVER ' . $conductorId . ' filtrado: ' . ($isFiltered ? 'SI' : 'NO'));
}

function driverViewingPayloadKey(int $solicitudId, int $conductorId): string
{
    return 'ride:' . $solicitudId . ':drivers_viewing:driver:' . $conductorId;
}

function removeDriverFromViewing($redis, int $solicitudId, int $conductorId): void
{
    $listKey = 'ride:' . $solicitudId . ':drivers_viewing';
    $payloadKey = driverViewingPayloadKey($solicitudId, $conductorId);

    $existingPayload = $redis->get($payloadKey);
    if (is_string($existingPayload) && trim($existingPayload) !== '') {
        $redis->lRem($listKey, 0, $existingPayload);
    }

    $rawEntries = $redis->lRange($listKey, 0, 20);
    if (is_array($rawEntries)) {
        foreach ($rawEntries as $rawEntry) {
            if (!is_string($rawEntry) || trim($rawEntry) === '') {
                continue;
            }

            $decoded = json_decode($rawEntry, true);
            if (!is_array($decoded)) {
                continue;
            }

            if ((int)($decoded['driver_id'] ?? 0) === $conductorId) {
                $redis->lRem($listKey, 0, $rawEntry);
            }
        }
    }

    $redis->del($payloadKey);
    $redis->expire($listKey, RIDE_VIEWING_TTL_SEC);
}

function resolveDriverName(PDO $db, int $conductorId): string
{
    if ($conductorId <= 0) {
        return 'Conductor';
    }

    try {
        $stmt = $db->prepare('SELECT nombre, apellido FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$conductorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'Conductor';
        }

        $nombre = trim((string)($row['nombre'] ?? ''));
        $apellido = trim((string)($row['apellido'] ?? ''));
        $fullName = trim($nombre . ' ' . $apellido);
        return $fullName !== '' ? $fullName : 'Conductor';
    } catch (Throwable $e) {
        return 'Conductor';
    }
}

function requeueRideAfterReject($redis, int $solicitudId): bool
{
    $requeueGateKey = 'ride:' . $solicitudId . ':requeue_after_reject';

    $acquired = false;
    try {
        $ok = $redis->set($requeueGateKey, '1', ['NX', 'EX' => REQUEUE_AFTER_REJECT_TTL_SEC]);
        $acquired = $ok === true || strtoupper((string)$ok) === 'OK';
    } catch (Throwable $e) {
        $ok = $redis->setnx($requeueGateKey, '1');
        if ($ok) {
            $redis->expire($requeueGateKey, REQUEUE_AFTER_REJECT_TTL_SEC);
            $acquired = true;
        }
    }

    if (!$acquired) {
        return false;
    }

    $redis->lPush('dispatch:trip_queue', (string)$solicitudId);
    $redis->lPush('ride_requests_queue', (string)$solicitudId);
    $redis->setex('ride:' . $solicitudId . ':matching_status', 180, 'searching');
    return true;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    if (!isset($data['solicitud_id']) || !isset($data['conductor_id'])) {
        throw new Exception('Datos requeridos: solicitud_id, conductor_id');
    }
    
    $solicitudId = (int) $data['solicitud_id'];
    $conductorId = (int) $data['conductor_id'];
    $motivo = trim((string) ($data['motivo'] ?? 'Conductor rechazó'));

    if ($solicitudId <= 0 || $conductorId <= 0) {
        throw new Exception('solicitud_id y conductor_id inválidos');
    }

    // Validar sesión para evitar rechazos emitidos por sesiones huérfanas.
    $sessionToken = driverSessionTokenFromRequest($data);
    $session = validateDriverSession($conductorId, $sessionToken, false);
    if (!$session['ok']) {
        throw new Exception($session['message']);
    }
    DriverGeoService::touchDriverHeartbeat($conductorId, 20);
    
    $database = new Database();
    $db = $database->getConnection();
    $driverDisplayName = resolveDriverName($db, $conductorId);

    $rechazoPersistidoEnDb = true;
    
    // Registrar rechazo en DB cuando exista la tabla (idempotente).
    try {
        $stmt = $db->prepare(" 
            INSERT INTO rechazos_conductor (
                solicitud_id,
                conductor_id,
                motivo,
                fecha_rechazo
            ) VALUES (?, ?, ?, NOW())
            ON CONFLICT (solicitud_id, conductor_id) DO NOTHING
        ");
        $stmt->execute([$solicitudId, $conductorId, $motivo]);
    } catch (Throwable $e) {
        if (isMissingRechazosTableError($e)) {
            $rechazoPersistidoEnDb = false;
            error_log('[reject_trip_request] Tabla rechazos_conductor no disponible: ' . $e->getMessage());
        } else {
            throw $e;
        }
    }

    // Cache auxiliar para que matching no vuelva a sugerir este conductor.
    Cache::set('trip_rejected:' . $solicitudId . ':' . $conductorId, '1', 600);

    // Si la solicitud quedó en estado recuperable, volverla a pendiente para continuar matching.
    $stmtResume = $db->prepare(" 
        UPDATE solicitudes_servicio
        SET estado = 'pendiente'
        WHERE id = ?
          AND LOWER(TRIM(COALESCE(estado, ''))) IN ('sin_conductores', 'timeout', 'exhausted')
          AND NOT EXISTS (
              SELECT 1
              FROM asignaciones_conductor ac
              WHERE ac.solicitud_id = ?
          )
    ");
    $stmtResume->execute([$solicitudId, $solicitudId]);

    $dispatchRequeued = false;

    try {
        $redis = Cache::redis();
        if ($redis) {
            rememberDriverRejectedForRide($redis, $solicitudId, $conductorId);
            removeDriverFromViewing($redis, $solicitudId, $conductorId);
            $redis->setex(
                'ride:' . $solicitudId . ':ui_message',
                RIDE_VIEWING_TTL_SEC,
                $driverDisplayName . ' no pudo aceptar el viaje'
            );

            // Cooldown inmediato tras rechazo explícito.
            $redis->setex('driver:cooldown:' . $conductorId, 30, '1');
            $redis->del('driver_offer_lock:' . $conductorId);
            $redis->setex('ride:' . $solicitudId . ':driver:' . $conductorId . ':status', 180, 'rejected');
            $currentDriverRaw = $redis->get('ride:' . $solicitudId . ':current_driver');
            if (is_string($currentDriverRaw) && (int)$currentDriverRaw === $conductorId) {
                $redis->del('ride:' . $solicitudId . ':current_driver');
            }

            $payload = json_encode([
                'driver_id' => $conductorId,
                'status' => 'rejected',
                'reason' => $motivo,
                'rejected_at' => gmdate('c'),
            ], JSON_UNESCAPED_UNICODE);

            $redis->publish('trip:responses:' . $solicitudId, $payload);
            $redis->lPush('trip:responses_queue:' . $solicitudId, $payload);
            $redis->expire('trip:responses_queue:' . $solicitudId, 120);
            $redis->incr('metrics:driver_rejections');

            $dispatchRequeued = requeueRideAfterReject($redis, $solicitudId);

            DriverGeoService::updateDriverStats($conductorId, null, 1.0, null);
        }
    } catch (Throwable $e) {}
    
    echo json_encode([
        'success' => true,
        'message' => $rechazoPersistidoEnDb
            ? 'Solicitud rechazada'
            : 'Solicitud rechazada (registro no guardado)',
        'dispatch_requeued' => $dispatchRequeued
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
