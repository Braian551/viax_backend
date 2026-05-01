<?php
header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/app.php';

const RIDE_VIEWING_LIMIT = 10;
const SEARCH_RADIUS_MAX_KM = 10.0;

function readSolicitudId(): int
{
    $raw = $_GET['solicitud_id'] ?? null;
    if ($raw === null || $raw === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'solicitud_id es requerido',
        ]);
        exit;
    }

    $id = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($id === false) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'solicitud_id inválido',
        ]);
        exit;
    }

    return (int)$id;
}

function fetchRideSummary(PDO $db, int $solicitudId): ?array
{
    $stmt = $db->prepare(
        "SELECT id, estado, fecha_creacion
         FROM solicitudes_servicio
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$solicitudId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function parseDriversViewing($redis, int $solicitudId): array
{
    if (!$redis) {
        return [];
    }

    $listKey = 'ride:' . $solicitudId . ':drivers_viewing';
    $rawEntries = $redis->lRange($listKey, 0, RIDE_VIEWING_LIMIT - 1);
    if (!is_array($rawEntries) || empty($rawEntries)) {
        return [];
    }

    $out = [];
    $seen = [];
    foreach ($rawEntries as $raw) {
        if (!is_string($raw) || trim($raw) === '') {
            continue;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }

        $driverId = (int)($decoded['driver_id'] ?? 0);
        if ($driverId <= 0 || isset($seen[$driverId])) {
            continue;
        }

        $seen[$driverId] = true;
        $name = trim((string)($decoded['name'] ?? ''));
        if ($name === '') {
            $name = 'Conductor';
        }

        $rating = isset($decoded['rating']) ? round((float)$decoded['rating'], 1) : 0.0;
        $etaMinutes = isset($decoded['eta_minutes']) ? (int)$decoded['eta_minutes'] : null;
        if ($etaMinutes === null || $etaMinutes <= 0) {
            $etaValue = trim((string)($decoded['eta'] ?? 'N/A'));
        } else {
            $etaValue = $etaMinutes . ' min';
        }

        $out[] = [
            'driver_id' => $driverId,
            'name' => $name,
            'rating' => $rating,
            'eta' => $etaValue,
            'distance_km' => isset($decoded['distance_km']) ? round((float)$decoded['distance_km'], 2) : 0.0,
            'photo' => (string)($decoded['photo'] ?? ''),
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return ((float)$a['distance_km']) <=> ((float)$b['distance_km']);
    });

    return array_slice($out, 0, RIDE_VIEWING_LIMIT);
}

function inferSearchRadiusKm(array $ride): float
{
    $createdRaw = (string)($ride['fecha_creacion'] ?? '');
    $createdAt = strtotime($createdRaw);
    $now = time();
    $ageSec = $createdAt !== false ? max(0, $now - $createdAt) : 0;

    if ($ageSec >= 120) {
        return 10.0;
    }
    if ($ageSec >= 90) {
        return 7.0;
    }
    if ($ageSec >= 60) {
        return 5.0;
    }
    if ($ageSec >= 30) {
        return 3.0;
    }

    return 2.0;
}

function readSearchRadius($redis, int $solicitudId, array $ride): float
{
    if ($redis) {
        $raw = $redis->get('ride:' . $solicitudId . ':search_radius_km');
        if (is_string($raw) && is_numeric($raw)) {
            return max(1.0, min(SEARCH_RADIUS_MAX_KM, (float)$raw));
        }
    }

    return inferSearchRadiusKm($ride);
}

function readMaxRadius($redis, int $solicitudId): float
{
    if ($redis) {
        $raw = $redis->get('ride:' . $solicitudId . ':max_radius_km');
        if (is_string($raw) && is_numeric($raw)) {
            return max(1.0, min(SEARCH_RADIUS_MAX_KM, (float)$raw));
        }
    }

    return SEARCH_RADIUS_MAX_KM;
}

function resolveMatchingStatus($redis, int $solicitudId): string
{
    if (!$redis) {
        return 'searching';
    }

    $raw = $redis->get('ride:' . $solicitudId . ':matching_status');
    if (!is_string($raw) || trim($raw) === '') {
        return 'searching';
    }

    return strtolower(trim($raw));
}

function resolveUiStatus(string $rideEstado, string $matchingStatus, int $driversViewingCount): string
{
    $estado = strtolower(trim($rideEstado));

    if (in_array($estado, ['aceptada', 'asignado', 'driver_assigned', 'en_camino', 'en_curso', 'completada', 'completado'], true)) {
        return 'ASSIGNED';
    }

    if (in_array($estado, ['sin_conductores', 'timeout', 'exhausted'], true)
        || in_array($matchingStatus, ['sin_conductores', 'timeout', 'exhausted'], true)) {
        return 'NO_DRIVERS';
    }

    if ($driversViewingCount > 0) {
        return 'DRIVER_VIEWING';
    }

    if (in_array($matchingStatus, ['checking', 'pending', 'offered'], true)) {
        return 'SENDING_REQUEST';
    }

    return 'SEARCHING';
}

function buildDefaultMessage(string $status, array $driversViewing): string
{
    if ($status === 'NO_DRIVERS') {
        return 'No hay conductores disponibles por ahora';
    }

    if ($status === 'ASSIGNED') {
        return 'Conductor asignado';
    }

    if ($status === 'DRIVER_VIEWING') {
        $count = count($driversViewing);
        if ($count <= 0) {
            return 'Conductores revisando tu solicitud';
        }
        if ($count === 1) {
            return '1 conductor está viendo tu solicitud';
        }
        return $count . ' conductores están viendo tu solicitud';
    }

    if ($status === 'SENDING_REQUEST') {
        $name = trim((string)($driversViewing[0]['name'] ?? ''));
        if ($name !== '') {
            return 'Enviando solicitud a ' . $name;
        }
        return 'Contactando conductores disponibles';
    }

    return 'Buscando conductores cercanos';
}

try {
    $solicitudId = readSolicitudId();

    $database = new Database();
    $db = $database->getConnection();

    $ride = fetchRideSummary($db, $solicitudId);
    if (!$ride) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Solicitud no encontrada',
        ]);
        exit;
    }

    $redis = Cache::redis();
    $driversViewing = parseDriversViewing($redis, $solicitudId);
    $matchingStatus = resolveMatchingStatus($redis, $solicitudId);
    $status = resolveUiStatus((string)($ride['estado'] ?? ''), $matchingStatus, count($driversViewing));
    $searchRadiusKm = readSearchRadius($redis, $solicitudId, $ride);
    $maxRadiusKm = readMaxRadius($redis, $solicitudId);

    $message = '';
    if ($redis) {
        $cachedMessage = $redis->get('ride:' . $solicitudId . ':ui_message');
        if (is_string($cachedMessage) && trim($cachedMessage) !== '') {
            $message = trim($cachedMessage);
        }
    }
    if ($message === '') {
        $message = buildDefaultMessage($status, $driversViewing);
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'drivers_viewing' => $driversViewing,
        'search_radius_km' => round($searchRadiusKm, 1),
        'max_radius_km' => round($maxRadiusKm, 1),
        'message' => $message,
        'matching_status' => $matchingStatus,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error obteniendo estado de búsqueda',
    ]);
}
