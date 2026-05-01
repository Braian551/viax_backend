<?php
/**
 * Endpoint: actualizar_ubicacion.php
 *
 * Este endpoint es usado por la app Flutter de conductor para enviar GPS
 * frecuente (cada pocos segundos). Para alto rendimiento:
 * - Escribe siempre en Redis (driver_location:{conductor_id}).
 * - Persiste en BD de forma periódica para reducir carga de escritura.
 * - Mantiene compatibilidad total del contrato JSON existente.
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/driver_service.php';
require_once __DIR__ . '/driver_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

/** Valida latitud en rango geográfico permitido. */
function isValidLat(float $lat): bool
{
    return $lat >= -90.0 && $lat <= 90.0;
}

/** Valida longitud en rango geográfico permitido. */
function isValidLng(float $lng): bool
{
    return $lng >= -180.0 && $lng <= 180.0;
}

/**
 * Guarda ubicación en Redis para lectura ultra-rápida en tiempo real.
 *
 * Claves:
 * - driver_location:{id} (legacy)
 * - driver:{id}:location (canónica realtime)
 * - driver:{id}:history (últimos puntos)
 * - active_drivers (set)
 */
function writeLocationToRedis(
    int $conductorId,
    float $lat,
    float $lng,
    ?float $speed,
    ?float $heading,
    int $timestampSec
): void
{
    $payload = json_encode([
        'lat' => $lat,
        'lng' => $lng,
        'speed' => $speed,
        'heading' => $heading,
        'timestamp' => $timestampSec,
    ]);

    Cache::set("driver_location:{$conductorId}", (string) $payload, 30);
    Cache::set("driver:{$conductorId}:location", (string) $payload, 30);
    Cache::sAdd('active_drivers', (string) $conductorId);
    DriverGeoService::upsertDriverLocation(
        $conductorId,
        $lat,
        $lng,
        $speed,
        $heading,
        $timestampSec
    );
    appendDriverHistory($conductorId, [
        'lat' => $lat,
        'lng' => $lng,
        'speed' => $speed,
        'heading' => $heading,
        'timestamp' => $timestampSec,
    ]);
}

/** Normaliza heading para dejarlo en rango [0, 360). */
function normalizeHeading(?float $heading): ?float
{
    if ($heading === null || !is_finite($heading)) {
        return null;
    }

    $normalized = fmod($heading, 360.0);
    if ($normalized < 0) {
        $normalized += 360.0;
    }

    return round($normalized, 2);
}

/**
 * Convierte timestamps (segundos/milisegundos/iso8601) a epoch en segundos.
 */
function normalizeEpochTimestamp($rawTimestamp): int
{
    $now = time();
    if ($rawTimestamp === null || $rawTimestamp === '') {
        return $now;
    }

    $timestamp = null;
    if (is_numeric($rawTimestamp)) {
        $numeric = (float) $rawTimestamp;
        $timestamp = $numeric > 1000000000000
            ? (int) floor($numeric / 1000)
            : (int) floor($numeric);
    } elseif (is_string($rawTimestamp)) {
        $parsed = strtotime($rawTimestamp);
        if ($parsed !== false) {
            $timestamp = $parsed;
        }
    }

    if ($timestamp === null || $timestamp <= 0) {
        return $now;
    }

    // Evita timestamps absurdos sin bloquear al cliente.
    if ($timestamp < ($now - 300) || $timestamp > ($now + 300)) {
        return $now;
    }

    return $timestamp;
}

/** Distancia Haversine en metros entre dos coordenadas. */
function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;
    $latDelta = deg2rad($lat2 - $lat1);
    $lngDelta = deg2rad($lng2 - $lng1);

    $a = sin($latDelta / 2) * sin($latDelta / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($lngDelta / 2) * sin($lngDelta / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/** Calcula bearing de A -> B en grados [0, 360). */
function calculateBearingDeg(float $fromLat, float $fromLng, float $toLat, float $toLng): float
{
    $fromLatRad = deg2rad($fromLat);
    $toLatRad = deg2rad($toLat);
    $deltaLngRad = deg2rad($toLng - $fromLng);

    $y = sin($deltaLngRad) * cos($toLatRad);
    $x = cos($fromLatRad) * sin($toLatRad)
        - sin($fromLatRad) * cos($toLatRad) * cos($deltaLngRad);

    $bearing = rad2deg(atan2($y, $x));
    $bearing = fmod(($bearing + 360.0), 360.0);

    return round($bearing, 2);
}

/**
 * Lee la última posición realtime para derivar velocidad y heading cuando el
 * cliente no los envía.
 */
function getLastDriverLocationSnapshot(int $conductorId): ?array
{
    $raw = Cache::get("driver:{$conductorId}:location");
    if (!is_string($raw) || trim($raw) === '') {
        $raw = Cache::get("driver_location:{$conductorId}");
    }
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $lat = isset($decoded['lat']) ? (float) $decoded['lat'] : null;
    $lng = isset($decoded['lng']) ? (float) $decoded['lng'] : null;
    $ts = isset($decoded['timestamp']) ? (int) $decoded['timestamp'] : 0;
    $heading = isset($decoded['heading']) ? (float) $decoded['heading'] : null;

    if ($lat === null || $lng === null || $ts <= 0) {
        return null;
    }

    return [
        'lat' => $lat,
        'lng' => $lng,
        'timestamp' => $ts,
        'heading' => normalizeHeading($heading),
    ];
}

/** Resuelve velocidad final (reportada o estimada por delta GPS). */
function resolveSpeedKmh(
    ?float $reportedSpeed,
    ?array $lastSnapshot,
    float $lat,
    float $lng,
    int $timestampSec
): float {
    if ($reportedSpeed !== null && is_finite($reportedSpeed) && $reportedSpeed >= 0 && $reportedSpeed <= 220) {
        return round($reportedSpeed, 2);
    }

    if ($lastSnapshot === null) {
        return 0.0;
    }

    $deltaSec = $timestampSec - (int) ($lastSnapshot['timestamp'] ?? 0);
    if ($deltaSec <= 0 || $deltaSec > 45) {
        return 0.0;
    }

    $meters = haversineMeters(
        (float) $lastSnapshot['lat'],
        (float) $lastSnapshot['lng'],
        $lat,
        $lng
    );
    $speedKmh = ($meters / max(1, $deltaSec)) * 3.6;
    if (!is_finite($speedKmh)) {
        return 0.0;
    }

    return round(min(220.0, max(0.0, $speedKmh)), 2);
}

/** Resuelve heading final (reportado o inferido por desplazamiento). */
function resolveHeading(
    ?float $reportedHeading,
    ?array $lastSnapshot,
    float $lat,
    float $lng
): ?float {
    $normalizedReported = normalizeHeading($reportedHeading);
    if ($normalizedReported !== null) {
        return $normalizedReported;
    }

    if ($lastSnapshot === null) {
        return null;
    }

    $meters = haversineMeters(
        (float) $lastSnapshot['lat'],
        (float) $lastSnapshot['lng'],
        $lat,
        $lng
    );
    if ($meters < 1.5) {
        return $lastSnapshot['heading'] ?? null;
    }

    return calculateBearingDeg(
        (float) $lastSnapshot['lat'],
        (float) $lastSnapshot['lng'],
        $lat,
        $lng
    );
}

/** Guarda un historial corto de posiciones recientes en Redis. */
function appendDriverHistory(int $conductorId, array $point): void
{
    $redis = Cache::redis();
    if (!$redis) {
        return;
    }

    $historyKey = "driver:{$conductorId}:history";
    $pointJson = json_encode($point, JSON_UNESCAPED_UNICODE);
    if (!is_string($pointJson) || $pointJson === '') {
        return;
    }

    $redis->lPush($historyKey, $pointJson);
    $redis->lTrim($historyKey, 0, 9); // Mantener últimos 10 puntos.
    $redis->expire($historyKey, 180);
}

/**
 * Determina si toca persistir en BD según un throttle por conductor.
 *
 * Esto reduce escrituras intensivas sin perder frescura en tiempo real,
 * ya que la app consumidora lee primero Redis.
 */
function shouldPersistToDatabase(int $conductorId, int $intervalSeconds = 12): bool
{
    $key = "driver_location:last_persist:{$conductorId}";
    $last = Cache::get($key);
    $now = time();

    if ($last === null) {
        Cache::set($key, (string) $now, $intervalSeconds + 5);
        return true;
    }

    $lastTs = (int) $last;
    if (($now - $lastTs) >= $intervalSeconds) {
        Cache::set($key, (string) $now, $intervalSeconds + 5);
        return true;
    }

    return false;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $conductorId = isset($input['conductor_id']) ? (int) $input['conductor_id'] : 0;
    $latitud = isset($input['latitud']) ? (float) $input['latitud'] : null;
    $longitud = isset($input['longitud']) ? (float) $input['longitud'] : null;
    $velocidad = isset($input['velocidad']) ? (float) $input['velocidad'] : null;
    $headingRaw = null;
    if (isset($input['heading'])) {
        $headingRaw = (float) $input['heading'];
    } elseif (isset($input['bearing'])) {
        $headingRaw = (float) $input['bearing'];
    }
    $timestampSec = normalizeEpochTimestamp(
        $input['timestamp']
            ?? $input['timestamp_ms']
            ?? null
    );

    if ($conductorId <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Validación de sesión (modo compatible para no romper clientes legacy).
    $sessionToken = driverSessionTokenFromRequest($input);
    $session = validateDriverSession($conductorId, $sessionToken, false);
    if (!$session['ok']) {
        throw new Exception($session['message']);
    }
    if ($latitud === null || $longitud === null) {
        throw new Exception('Latitud y longitud son requeridas');
    }
    if (!is_finite($latitud) || !is_finite($longitud) || !isValidLat($latitud) || !isValidLng($longitud)) {
        throw new Exception('Coordenadas inválidas');
    }

    $lastSnapshot = getLastDriverLocationSnapshot($conductorId);
    $speedKmh = resolveSpeedKmh($velocidad, $lastSnapshot, $latitud, $longitud, $timestampSec);
    $heading = resolveHeading($headingRaw, $lastSnapshot, $latitud, $longitud);

    // Escritura rápida en cache (camino crítico realtime).
    writeLocationToRedis(
        $conductorId,
        $latitud,
        $longitud,
        $speedKmh,
        $heading,
        $timestampSec
    );
    DriverGeoService::touchDriverHeartbeat($conductorId, 20);
    DriverGeoService::setDriverState($conductorId, 'available');

    // Persistencia periódica en BD para descargar I/O.
    $persistirBd = shouldPersistToDatabase($conductorId);

    if ($persistirBd) {
        $database = new Database();
        $db = $database->getConnection();

        $query = "UPDATE detalles_conductor
                  SET latitud_actual = :latitud,
                      longitud_actual = :longitud,
                      ultima_actualizacion = NOW()
                  WHERE usuario_id = :conductor_id";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':latitud', $latitud);
        $stmt->bindParam(':longitud', $longitud);
        $stmt->bindParam(':conductor_id', $conductorId, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            $queryInsert = "INSERT INTO detalles_conductor
                            (usuario_id, latitud_actual, longitud_actual, fecha_creacion, ultima_actualizacion)
                            VALUES (:conductor_id, :latitud, :longitud, NOW(), NOW())";

            $stmtInsert = $db->prepare($queryInsert);
            $stmtInsert->bindParam(':conductor_id', $conductorId, PDO::PARAM_INT);
            $stmtInsert->bindParam(':latitud', $latitud);
            $stmtInsert->bindParam(':longitud', $longitud);
            $stmtInsert->execute();
        }

        try {
            $gridId = DriverGeoService::gridIdForCoordinates($latitud, $longitud);
            $cityId = DriverGeoService::getCityIdFromCoordinates($latitud, $longitud);
            $stmtLive = $db->prepare("\n                INSERT INTO drivers_live_location (\n                    conductor_id, lat, lng, speed_kmh, grid_id, city_id, source, updated_at\n                ) VALUES (\n                    :conductor_id, :lat, :lng, :speed_kmh, :grid_id, :city_id, 'heartbeat', NOW()\n                )\n                ON CONFLICT (conductor_id) DO UPDATE SET\n                    lat = EXCLUDED.lat,\n                    lng = EXCLUDED.lng,\n                    speed_kmh = EXCLUDED.speed_kmh,\n                    grid_id = EXCLUDED.grid_id,\n                    city_id = EXCLUDED.city_id,\n                    source = EXCLUDED.source,\n                    updated_at = NOW()\n            ");
            $stmtLive->execute([
                ':conductor_id' => $conductorId,
                ':lat' => $latitud,
                ':lng' => $longitud,
                ':speed_kmh' => $speedKmh,
                ':grid_id' => $gridId,
                ':city_id' => $cityId,
            ]);
        } catch (Throwable $e) {
            error_log('actualizar_ubicacion.php drivers_live_location warning: ' . $e->getMessage());
        }

        // Importante: este endpoint SOLO actualiza ubicación del conductor.
        // Las métricas canónicas de distancia/tiempo se calculan en
        // /driver/tracking/update y en finalize para evitar inflado por payloads legacy.
    }

    echo json_encode([
        'success' => true,
        'message' => 'Ubicación actualizada exitosamente',
        'data' => [
            'lat' => $latitud,
            'lng' => $longitud,
            'speed' => $speedKmh,
            'heading' => $heading,
            'timestamp' => $timestampSec,
        ],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log('actualizar_ubicacion.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
