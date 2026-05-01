<?php
/**
 * Endpoint semántico para update unitario de tracking.
 * Endpoint: POST /trip/tracking_update
 *
 * No reemplaza contratos legacy; reutiliza el pipeline existente.
 */

header('Content-Type: application/json');
// CORS seguro: solo dominios controlados; requests sin Origin (app movil) se permiten.
$allowedOrigins = [
    'https://viaxcol.online',
    'https://www.viaxcol.online',
];
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif ($origin !== '') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CORS blocked']);
    exit();
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../conductor/tracking/tracking_ingest_service.php';

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('JSON inválido');
    }

    $tripId = isset($payload['trip_id'])
        ? intval($payload['trip_id'])
        : intval($payload['solicitud_id'] ?? 0);

    $conductorId = intval($payload['conductor_id'] ?? 0);
    $lat = floatval($payload['lat'] ?? $payload['latitud'] ?? 0);
    $lng = floatval($payload['lng'] ?? $payload['longitud'] ?? 0);
    $speed = floatval($payload['speed'] ?? $payload['velocidad'] ?? 0);
    $accuracy = isset($payload['accuracy']) ? floatval($payload['accuracy']) : null;
    $heading = floatval($payload['heading'] ?? $payload['bearing'] ?? 0);
    $fase = strval($payload['fase_viaje'] ?? 'hacia_destino');

    if ($tripId <= 0 || $conductorId <= 0) {
        throw new Exception('trip_id y conductor_id son requeridos');
    }

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        throw new Exception('Coordenadas inválidas');
    }

    $database = new Database();
    $db = $database->getConnection();

    [$lastLat, $lastLng, $lastTiempo] = obtenerUltimoEstadoTracking($db, $tripId);

    $lastDistKm = 0.0;
    $stmtLast = $db->prepare(" 
        SELECT distancia_acumulada_km, tiempo_transcurrido_seg
        FROM viaje_tracking_snapshot
        WHERE solicitud_id = :trip_id
        LIMIT 1
    ");
    try {
      $stmtLast->execute([':trip_id' => $tripId]);
      $lastRow = $stmtLast->fetch(PDO::FETCH_ASSOC);
      if ($lastRow) {
          $lastDistKm = floatval($lastRow['distancia_acumulada_km'] ?? 0);
          $lastTiempo = intval($lastRow['tiempo_transcurrido_seg'] ?? $lastTiempo ?? 0);
      }
    } catch (Exception $_) {
      // Si no existe snapshot o falla la consulta, usamos fallback en realtime.
      $stmtLastRt = $db->prepare(" 
          SELECT distancia_acumulada_km, tiempo_transcurrido_seg
          FROM viaje_tracking_realtime
          WHERE solicitud_id = :trip_id
          ORDER BY timestamp_gps DESC
          LIMIT 1
      ");
      $stmtLastRt->execute([':trip_id' => $tripId]);
      $lastRowRt = $stmtLastRt->fetch(PDO::FETCH_ASSOC);
      if ($lastRowRt) {
          $lastDistKm = floatval($lastRowRt['distancia_acumulada_km'] ?? 0);
          $lastTiempo = intval($lastRowRt['tiempo_transcurrido_seg'] ?? $lastTiempo ?? 0);
      }
    }

    $distanciaDeltaM = 0.0;
    if ($lastLat !== null && $lastLng !== null) {
        $distanciaDeltaM = calcularDistanciaHaversine($lastLat, $lastLng, $lat, $lng);
    }

    $distanciaAcumuladaKm = max(0.0, $lastDistKm + ($distanciaDeltaM / 1000.0));
    if (isset($payload['distancia_acumulada_km'])) {
        $distanciaAcumuladaKm = max($distanciaAcumuladaKm, floatval($payload['distancia_acumulada_km']));
    }

    $tiempoTranscurridoSeg = max(0, intval($lastTiempo ?? 0));
    if (isset($payload['tiempo_transcurrido_seg'])) {
        $tiempoTranscurridoSeg = max($tiempoTranscurridoSeg, intval($payload['tiempo_transcurrido_seg']));
    }

    $point = [
        'latitud' => $lat,
        'longitud' => $lng,
        'velocidad' => $speed,
        'bearing' => $heading,
        'precision_gps' => $accuracy,
        'distancia_acumulada_km' => $distanciaAcumuladaKm,
        'tiempo_transcurrido_seg' => $tiempoTranscurridoSeg,
        'fase_viaje' => $fase,
        'timestamp_gps' => $payload['timestamp'] ?? null,
    ];

    $db->beginTransaction();
    $result = processTrackingPoints($db, $tripId, $conductorId, [$point]);
    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Tracking procesado',
        'trip_id' => $tripId,
        'inserted' => $result['inserted'],
        'skipped' => $result['skipped'],
        'ignored' => (bool)($result['ignored'] ?? false),
        'ignored_reason' => $result['ignored_reason'] ?? null,
    ]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
