<?php
/**
 * Backfill de métricas canónicas para viajes históricos completados.
 *
 * Repara viajes con estado completado/entregado donde metrics_locked=false
 * para garantizar que cliente y conductor consulten la misma fuente inmutable.
 *
 * Uso:
 *   php backend/scripts/backfill_canonical_trip_metrics.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../conductor/tracking/tracking_schema_helpers.php';

const BATCH_SIZE = 100;

function toFloatOrNull($v): ?float {
    if ($v === null || $v === '') return null;
    return (float) $v;
}

function toIntOrNull($v): ?int {
    if ($v === null || $v === '') return null;
    return (int) $v;
}

function computeGpsDistanceKm(PDO $db, int $solicitudId): ?float {
    $stmt = $db->prepare("\n        SELECT latitud, longitud\n        FROM viaje_tracking_realtime\n        WHERE solicitud_id = :id\n        ORDER BY COALESCE(timestamp_gps, timestamp_servidor) ASC\n    ");
    $stmt->execute([':id' => $solicitudId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) < 2) {
        return null;
    }

    $distMeters = 0.0;
    $prev = null;
    foreach ($rows as $row) {
        $lat = (float) ($row['latitud'] ?? 0);
        $lng = (float) ($row['longitud'] ?? 0);
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            continue;
        }

        if ($prev !== null) {
            $dLat = deg2rad($lat - $prev['lat']);
            $dLon = deg2rad($lng - $prev['lng']);
            $a = sin($dLat / 2) ** 2
                + cos(deg2rad($prev['lat'])) * cos(deg2rad($lat)) * sin($dLon / 2) ** 2;
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
            $distMeters += 6371000.0 * $c;
        }

        $prev = ['lat' => $lat, 'lng' => $lng];
    }

    return round($distMeters / 1000.0, 3);
}

function computeGpsDurationSeg(PDO $db, int $solicitudId): ?int {
    $stmt = $db->prepare("\n        SELECT\n            MIN(COALESCE(timestamp_gps, timestamp_servidor)) AS ts_ini,\n            MAX(COALESCE(timestamp_gps, timestamp_servidor)) AS ts_fin\n        FROM viaje_tracking_realtime\n        WHERE solicitud_id = :id\n    ");
    $stmt->execute([':id' => $solicitudId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $ini = strtotime((string) ($row['ts_ini'] ?? ''));
    $fin = strtotime((string) ($row['ts_fin'] ?? ''));

    if ($ini === false || $fin === false || $fin < $ini) {
        return null;
    }

    return $fin - $ini;
}

try {
    $db = (new Database())->getConnection();

    $hasLocked = trackingColumnExists($db, 'solicitudes_servicio', 'metrics_locked');
    $hasDistanceFinal = trackingColumnExists($db, 'solicitudes_servicio', 'distance_final');
    $hasDurationFinal = trackingColumnExists($db, 'solicitudes_servicio', 'duration_final');
    $hasPriceFinalEn = trackingColumnExists($db, 'solicitudes_servicio', 'price_final');
    $hasFinalizedAt = trackingColumnExists($db, 'solicitudes_servicio', 'finalized_at');

    if (!$hasLocked || !$hasDistanceFinal || !$hasDurationFinal || !$hasPriceFinalEn || !$hasFinalizedAt) {
        throw new RuntimeException('Faltan columnas canónicas. Ejecuta migración 046 primero.');
    }

    while (true) {
        $stmt = $db->prepare("\n            SELECT\n                id,\n                distancia_estimada,\n                tiempo_estimado,\n                precio_estimado,\n                distancia_recorrida,\n                tiempo_transcurrido,\n                precio_final\n            FROM solicitudes_servicio\n            WHERE estado IN ('completada', 'entregado')\n              AND COALESCE(metrics_locked, FALSE) = FALSE\n            ORDER BY id ASC\n            LIMIT :limit\n        ");
        $stmt->bindValue(':limit', BATCH_SIZE, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo "[BackfillMigration] No quedan viajes pendientes.\n";
            break;
        }

        foreach ($rows as $row) {
            $tripId = (int) $row['id'];

            $gpsDistance = computeGpsDistanceKm($db, $tripId);
            $gpsDuration = computeGpsDurationSeg($db, $tripId);

            // Regla: priorizar tracking real; si no existe, fallback legacy/estimado.
            $distanceFinal = $gpsDistance
                ?? toFloatOrNull($row['distancia_recorrida'])
                ?? toFloatOrNull($row['distancia_estimada'])
                ?? 0.0;

            $durationFinal = $gpsDuration
                ?? toIntOrNull($row['tiempo_transcurrido'])
                ?? ((int) ((toIntOrNull($row['tiempo_estimado']) ?? 0) * 60));

            $priceFinal = toFloatOrNull($row['precio_final'])
                ?? toFloatOrNull($row['precio_estimado'])
                ?? 0.0;

            $upd = $db->prepare("\n                UPDATE solicitudes_servicio\n                SET\n                    distance_final = :distance_final,\n                    duration_final = :duration_final,\n                    price_final = :price_final,\n                    metrics_locked = TRUE,\n                    finalized_at = COALESCE(finalized_at, NOW())\n                WHERE id = :id\n            ");
            $upd->execute([
                ':distance_final' => $distanceFinal,
                ':duration_final' => $durationFinal,
                ':price_final' => $priceFinal,
                ':id' => $tripId,
            ]);

            echo "[BackfillMigration] Fixed trip {$tripId} metrics." . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[BackfillMigration] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
