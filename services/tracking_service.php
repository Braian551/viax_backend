<?php
/**
 * Servicio de tracking (capa de orquestación liviana).
 */

require_once __DIR__ . '/../config/app.php';

class TrackingService
{
    public static function writeRealtimeState(
        int $tripId,
        float $lat,
        float $lng,
        float $speed,
        float $heading,
        int $timestampSec,
        array $metrics
    ): void {
        $stateKey = 'trip:' . $tripId . ':state';
        $metricsKey = 'trip:' . $tripId . ':metrics';
        $ttl = 7200;

        Cache::set($stateKey, (string)json_encode([
            'lat' => $lat,
            'lng' => $lng,
            'speed' => $speed,
            'heading' => $heading,
            'timestamp' => gmdate('c', $timestampSec),
        ], JSON_UNESCAPED_UNICODE), $ttl);

        $redis = Cache::redis();
        if ($redis) {
            $redis->setex($metricsKey, $ttl, json_encode($metrics, JSON_UNESCAPED_UNICODE));
            $redis->publish('trip_updates:' . $tripId, json_encode([
                'trip_id' => $tripId,
                'tracking_actual' => [
                    'ubicacion' => ['latitud' => $lat, 'longitud' => $lng],
                    'distancia_km' => (float)($metrics['distance_km'] ?? 0),
                    'tiempo_segundos' => (int)($metrics['elapsed_time_sec'] ?? 0),
                    'precio_actual' => (float)($metrics['price'] ?? 0),
                    'velocidad_kmh' => $speed,
                    'heading_deg' => $heading,
                    'fase' => 'hacia_destino',
                    'ultima_actualizacion' => gmdate('c', $timestampSec),
                ],
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    public static function incrMetric(string $metricKey, int $by = 1): void
    {
        $redis = Cache::redis();
        if (!$redis) {
            return;
        }

        try {
            if ($by === 1) {
                $redis->incr($metricKey);
            } else {
                $redis->incrBy($metricKey, $by);
            }
        } catch (Throwable $e) {
            error_log('[TrackingService] metric warning: ' . $e->getMessage());
        }
    }
}
