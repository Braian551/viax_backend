<?php

require_once __DIR__ . '/../core/Cache.php';

/**
 * Publicador del contrato de entrada para dispatch-service.
 *
 * Usa Pub/Sub sobre `dispatch:trip_queue` sin tocar la cola legacy basada en listas.
 * Redis maneja Pub/Sub y listas de forma independiente aunque compartan el nombre.
 */
class DispatchServicePublisher
{
    private const CHANNEL = 'dispatch:trip_queue';
    private const LAST_EVENT_TTL_SEC = 600;

    private static function currentDispatchMode(): string
    {
        return trim((string)(getenv('DISPATCH_MODE') ?: 'hybrid')) ?: 'hybrid';
    }

    public static function isEnabled(): bool
    {
        $raw = getenv('DISPATCH_SERVICE_PUBLISH_ENABLED');
        if ($raw === false) {
            return true;
        }

        $normalized = strtolower(trim((string)$raw));
        return !in_array($normalized, ['0', 'false', 'off', 'no'], true);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function publishTripRequested(int $tripId, array $payload): bool
    {
        if ($tripId <= 0 || !self::isEnabled()) {
            return false;
        }

        try {
            $redis = Cache::redis();
            if (!$redis) {
                return false;
            }

            $event = self::buildTripRequestedEvent($tripId, $payload);
            $serialized = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($serialized) || $serialized === '') {
                return false;
            }

            $redis->publish(self::CHANNEL, $serialized);
            Cache::set('dispatch_service:last_event:' . $tripId, $serialized, self::LAST_EVENT_TTL_SEC);
            return true;
        } catch (Throwable $e) {
            error_log('[DispatchServicePublisher] warning: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function buildTripRequestedEvent(int $tripId, array $payload): array
    {
        $candidateDriverIds = [];
        foreach ((array)($payload['candidate_driver_ids'] ?? []) as $driverId) {
            $driverId = (int)$driverId;
            if ($driverId > 0) {
                $candidateDriverIds[] = $driverId;
            }
        }

        $candidateDriverIds = array_values(array_unique($candidateDriverIds));

        return [
            'event' => 'trip.requested',
            'trip_id' => $tripId,
            'request_id' => $tripId,
            'user_id' => max(0, (int)($payload['user_id'] ?? 0)),
            'lat' => round((float)($payload['lat'] ?? 0.0), 6),
            'lng' => round((float)($payload['lng'] ?? 0.0), 6),
            'vehicle_type' => trim((string)($payload['vehicle_type'] ?? 'moto')) ?: 'moto',
            'timestamp' => (string)($payload['timestamp'] ?? gmdate('c')),
            'estimated_price' => round(max(0.0, (float)($payload['estimated_price'] ?? 0.0)), 2),
            'company_id' => isset($payload['company_id']) && $payload['company_id'] !== null
                ? (int)$payload['company_id']
                : null,
            'candidate_driver_ids' => $candidateDriverIds,
            'source' => 'core',
            'mode' => self::currentDispatchMode(),
        ];
    }
}
