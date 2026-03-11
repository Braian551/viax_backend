<?php
/**
 * Worker de persistencia asíncrona para tracking.
 *
 * Consume trip_tracking_queue (Redis) y persiste por lotes:
 * - Tamaño de lote: 50 puntos
 * - Flush temporal: 5 segundos
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../conductor/tracking/tracking_ingest_service.php';

const TRACKING_QUEUE_KEY = 'trip_tracking_queue';
const BATCH_SIZE = 50;
const FLUSH_SECONDS = 5;

function decodeQueueItem($raw): ?array {
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $tripId = isset($decoded['trip_id']) ? intval($decoded['trip_id']) : 0;
    $conductorId = isset($decoded['conductor_id']) ? intval($decoded['conductor_id']) : 0;
    if ($tripId <= 0 || $conductorId <= 0) {
        return null;
    }

    return $decoded;
}

function flushBatch(PDO $db, array $batch): void {
    if (empty($batch)) {
        return;
    }

    $insertStmt = $db->prepare(
        'INSERT INTO trip_tracking_points (trip_id, lat, lng, "timestamp")
         VALUES (:trip_id, :lat, :lng, :ts)'
    );

    $grouped = [];
    foreach ($batch as $item) {
        $tripId = intval($item['trip_id']);
        $conductorId = intval($item['conductor_id']);

        $lat = floatval($item['lat'] ?? 0);
        $lng = floatval($item['lng'] ?? 0);
        $ts = isset($item['timestamp']) && is_string($item['timestamp'])
            ? $item['timestamp']
            : gmdate('c', intval($item['timestamp_sec'] ?? time()));

        $insertStmt->execute([
            ':trip_id' => $tripId,
            ':lat' => $lat,
            ':lng' => $lng,
            ':ts' => $ts,
        ]);

        $groupKey = $tripId . ':' . $conductorId;
        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [
                'trip_id' => $tripId,
                'conductor_id' => $conductorId,
                'points' => [],
            ];
        }

        $grouped[$groupKey]['points'][] = [
            'latitud' => $lat,
            'longitud' => $lng,
            'velocidad' => floatval($item['speed'] ?? 0),
            'bearing' => floatval($item['heading'] ?? 0),
            'precision_gps' => isset($item['precision_gps']) ? floatval($item['precision_gps']) : null,
            'distancia_acumulada_km' => floatval($item['distance_km'] ?? 0),
            'tiempo_transcurrido_seg' => intval($item['elapsed_time_sec'] ?? 0),
            'fase_viaje' => 'hacia_destino',
            'evento' => null,
        ];
    }

    foreach ($grouped as $group) {
        processTrackingPoints(
            $db,
            $group['trip_id'],
            $group['conductor_id'],
            $group['points']
        );
    }
}

$redis = Cache::redis();
if (!$redis) {
    fwrite(STDERR, "[tracking_worker] Redis no disponible\n");
    exit(1);
}

$database = new Database();
$db = $database->getConnection();

$batch = [];
$lastFlush = microtime(true);

while (true) {
    try {
        $entry = $redis->brPop([TRACKING_QUEUE_KEY], 2);
        if (is_array($entry) && isset($entry[1])) {
            $item = decodeQueueItem($entry[1]);
            if ($item !== null) {
                $batch[] = $item;
            }
        }

        $timeToFlush = (microtime(true) - $lastFlush) >= FLUSH_SECONDS;
        $sizeToFlush = count($batch) >= BATCH_SIZE;

        if (!empty($batch) && ($timeToFlush || $sizeToFlush)) {
            $db->beginTransaction();
            flushBatch($db, $batch);
            $db->commit();

            $batch = [];
            $lastFlush = microtime(true);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[tracking_worker] ' . $e->getMessage());
        usleep(400000);
    }
}
