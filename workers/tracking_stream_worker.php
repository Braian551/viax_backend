<?php
/**
 * Worker de tracking basado en Redis Streams.
 *
 * Responsabilidades:
 * - Consumir trip_tracking_stream con XREADGROUP.
 * - Reintentar mensajes pendientes con XAUTOCLAIM.
 * - Insertar por lotes en trip_tracking_points.
 * - Confirmar mensajes con XACK.
 */

require_once __DIR__ . '/../config/app.php';

const STREAM_KEY = 'trip_tracking_stream';
const STREAM_GROUP = 'tracking_workers';
const BATCH_MAX = 100;
const FLUSH_INTERVAL_SEC = 3;
const PENDING_MIN_IDLE_MS = 30000;

function workerName(): string {
    return 'worker-' . gethostname() . '-' . getmypid();
}

function ensureGroup($redis): void {
    try {
        $redis->rawCommand('XGROUP', 'CREATE', STREAM_KEY, STREAM_GROUP, '0', 'MKSTREAM');
    } catch (Throwable $e) {
        // BUSYGROUP esperado cuando ya existe.
    }
}

function parseStreamResult($rows): array {
    $items = [];
    if (!is_array($rows) || empty($rows)) {
        return $items;
    }

    foreach ($rows as $streamRows) {
        if (!is_array($streamRows) || count($streamRows) < 2) {
            continue;
        }

        $messages = $streamRows[1] ?? [];
        if (!is_array($messages)) {
            continue;
        }

        foreach ($messages as $message) {
            if (!is_array($message) || count($message) < 2) {
                continue;
            }

            $id = (string)($message[0] ?? '');
            $fieldsRaw = $message[1] ?? [];
            $fields = [];

            if (array_is_list($fieldsRaw)) {
                for ($i = 0; $i < count($fieldsRaw) - 1; $i += 2) {
                    $fields[(string)$fieldsRaw[$i]] = $fieldsRaw[$i + 1];
                }
            } elseif (is_array($fieldsRaw)) {
                $fields = $fieldsRaw;
            }

            if ($id !== '' && !empty($fields)) {
                $items[] = ['id' => $id, 'fields' => $fields];
            }
        }
    }

    return $items;
}

function toInt($v, int $default = 0): int {
    if ($v === null || $v === '') return $default;
    return intval($v);
}

function toFloat($v, float $default = 0.0): float {
    if ($v === null || $v === '') return $default;
    return floatval($v);
}

function flushBatch(PDO $db, array $batch): int {
    if (empty($batch)) {
        return 0;
    }

    $stmt = $db->prepare(
        'INSERT INTO trip_tracking_points (trip_id, lat, lng, speed, heading, "timestamp", created_at)
         VALUES (:trip_id, :lat, :lng, :speed, :heading, :ts, NOW())'
    );

    $inserted = 0;
    foreach ($batch as $msg) {
        $f = $msg['fields'];
        $tripId = toInt($f['trip_id'] ?? 0);
        if ($tripId <= 0) {
            continue;
        }

        $stmt->execute([
            ':trip_id' => $tripId,
            ':lat' => toFloat($f['lat'] ?? 0),
            ':lng' => toFloat($f['lng'] ?? 0),
            ':speed' => toFloat($f['speed'] ?? 0),
            ':heading' => toFloat($f['heading'] ?? 0),
            ':ts' => toInt($f['timestamp'] ?? time()),
        ]);

        $inserted++;
    }

    return $inserted;
}

$redis = Cache::redis();
if (!$redis) {
    fwrite(STDERR, "[tracking_stream_worker] Redis no disponible\n");
    exit(1);
}

ensureGroup($redis);

$database = new Database();
$db = $database->getConnection();
$consumer = workerName();
$batch = [];
$batchIds = [];
$lastFlush = microtime(true);

while (true) {
    try {
        // Recupera pendientes huérfanos antes de leer nuevos.
        $claimedRaw = $redis->rawCommand(
            'XAUTOCLAIM', STREAM_KEY, STREAM_GROUP, $consumer,
            (string)PENDING_MIN_IDLE_MS, '0-0', 'COUNT', '50'
        );

        if (is_array($claimedRaw) && isset($claimedRaw[1]) && is_array($claimedRaw[1])) {
            foreach ($claimedRaw[1] as $entry) {
                if (!is_array($entry) || count($entry) < 2) continue;
                $batch[] = ['id' => (string)$entry[0], 'fields' => is_array($entry[1]) ? $entry[1] : []];
                $batchIds[] = (string)$entry[0];
            }
        }

        $rows = $redis->rawCommand(
            'XREADGROUP', 'GROUP', STREAM_GROUP, $consumer,
            'COUNT', (string)BATCH_MAX,
            'BLOCK', '3000',
            'STREAMS', STREAM_KEY, '>'
        );

        $messages = parseStreamResult($rows);
        foreach ($messages as $message) {
            $batch[] = $message;
            $batchIds[] = $message['id'];
        }

        $timeDue = (microtime(true) - $lastFlush) >= FLUSH_INTERVAL_SEC;
        $sizeDue = count($batch) >= BATCH_MAX;

        if (!empty($batch) && ($timeDue || $sizeDue)) {
            $db->beginTransaction();
            $inserted = flushBatch($db, $batch);
            $db->commit();

            if (!empty($batchIds)) {
                $args = array_merge(['XACK', STREAM_KEY, STREAM_GROUP], $batchIds);
                $redis->rawCommand(...$args);
            }

            $redis->incrBy('metrics:worker:batch_insert', max(0, $inserted));

            $batch = [];
            $batchIds = [];
            $lastFlush = microtime(true);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[tracking_stream_worker] ' . $e->getMessage());
        usleep(300000);
    }
}
