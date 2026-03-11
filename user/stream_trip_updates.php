<?php
/**
 * API: Stream SSE de tracking por viaje (pasajero)
 * Endpoint: GET /user/stream_trip_updates.php
 *
 * Modelo push:
 * - Lee estado realtime desde Redis key trip:{trip_id}:state.
 * - Emite eventos SSE solo cuando hay cambios de firma.
 * - Mantiene fallback seguro (timeout + keepalive).
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo "event: error\n";
    echo 'data: ' . json_encode(['success' => false, 'message' => 'Metodo no permitido']) . "\n\n";
    exit();
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

require_once __DIR__ . '/../config/app.php';

function parseWaitSeconds($rawValue): int {
    $value = intval($rawValue ?? 25);
    if ($value < 10) return 10;
    if ($value > 60) return 60;
    return $value;
}

function sendEvent(string $event, array $payload): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

function safeJsonDecode(?string $raw): ?array {
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : null;
}

function loadTripTrackingPayload(int $tripId): ?array {
    $rawState = Cache::get('trip:' . $tripId . ':state');
    $state = safeJsonDecode(is_string($rawState) ? $rawState : null);
    if ($state === null) {
        return null;
    }

    $metrics = null;
    $redis = Cache::redis();
    if ($redis) {
        $rawMetrics = $redis->get('trip:' . $tripId . ':metrics');
        $metrics = safeJsonDecode(is_string($rawMetrics) ? $rawMetrics : null);

        // Compatibilidad con hash legacy.
        if ($metrics === null) {
            $legacy = $redis->hGetAll('trip:' . $tripId . ':metrics');
            if (is_array($legacy) && !empty($legacy)) {
                $metrics = [
                    'distance_km' => isset($legacy['distance_total_km']) ? floatval($legacy['distance_total_km']) : 0,
                    'elapsed_time_sec' => isset($legacy['elapsed_time_sec']) ? intval($legacy['elapsed_time_sec']) : 0,
                    'avg_speed_kmh' => isset($legacy['avg_speed_kmh']) ? floatval($legacy['avg_speed_kmh']) : 0,
                ];
            }
        }
    }

    return [
        'state' => $state,
        'metrics' => $metrics ?? [],
    ];
}

try {
    $tripId = isset($_GET['trip_id']) ? intval($_GET['trip_id']) : 0;
    $waitSeconds = parseWaitSeconds($_GET['wait_seconds'] ?? 25);
    $sinceSignature = isset($_GET['since_signature'])
        ? preg_replace('/[^a-f0-9]/i', '', (string)$_GET['since_signature'])
        : '';

    if ($tripId <= 0) {
        sendEvent('error', [
            'success' => false,
            'message' => 'trip_id es requerido',
        ]);
        exit();
    }

    $deadline = microtime(true) + $waitSeconds;
    $heartbeatEvery = 20;
    $nextHeartbeat = microtime(true) + $heartbeatEvery;
    $lastSignature = $sinceSignature;

    sendEvent('connected', [
        'success' => true,
        'trip_id' => $tripId,
        'server_time' => gmdate('c'),
    ]);

    while (!connection_aborted() && microtime(true) < $deadline) {
        $trackingPayload = loadTripTrackingPayload($tripId);
        $state = $trackingPayload['state'] ?? null;
        $metrics = $trackingPayload['metrics'] ?? [];

        if ($state !== null) {
            $signature = sha1(json_encode([
                $state['lat'] ?? null,
                $state['lng'] ?? null,
                $metrics['distance_km'] ?? null,
                $metrics['elapsed_time_sec'] ?? null,
                $state['timestamp'] ?? null,
                $state['fase'] ?? null,
            ]));

            if ($signature !== $lastSignature) {
                $payload = [
                    'success' => true,
                    'trip_id' => $tripId,
                    'signature' => $signature,
                    'tracking_actual' => [
                        'ubicacion' => [
                            'latitud' => isset($state['lat']) ? floatval($state['lat']) : null,
                            'longitud' => isset($state['lng']) ? floatval($state['lng']) : null,
                        ],
                        'distancia_km' => isset($metrics['distance_km']) ? floatval($metrics['distance_km']) : 0,
                        'tiempo_segundos' => isset($metrics['elapsed_time_sec']) ? intval($metrics['elapsed_time_sec']) : 0,
                        'precio_actual' => isset($metrics['price']) ? floatval($metrics['price']) : 0,
                        'velocidad_kmh' => isset($state['speed']) ? floatval($state['speed']) : 0,
                        'heading_deg' => isset($state['heading']) ? floatval($state['heading']) : 0,
                        'fase' => $state['fase'] ?? 'hacia_destino',
                        'ultima_actualizacion' => $state['timestamp'] ?? gmdate('c'),
                    ],
                ];

                sendEvent('trip_update', $payload);
                $lastSignature = $signature;
            }
        }

        if (microtime(true) >= $nextHeartbeat) {
            sendEvent('keepalive', [
                'success' => true,
                'trip_id' => $tripId,
                'server_time' => gmdate('c'),
            ]);
            $nextHeartbeat = microtime(true) + $heartbeatEvery;
        }

        usleep(250000);
    }

    sendEvent('timeout', [
        'success' => true,
        'trip_id' => $tripId,
        'server_time' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    error_log('stream_trip_updates.php Error: ' . $e->getMessage());
    sendEvent('error', [
        'success' => false,
        'message' => 'Error interno de stream',
    ]);
}
