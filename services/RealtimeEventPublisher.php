<?php
/**
 * Viax Realtime Event Publisher
 *
 * Publicador centralizado de eventos al Realtime Gateway via Redis Pub/Sub.
 * El gateway Node.js suscribe a canales con prefijo "viax:" y los reenvía
 * por WebSocket a los clientes Flutter.
 *
 * Uso:
 *   require_once __DIR__ . '/../services/RealtimeEventPublisher.php';
 *
 *   // Evento de estado de viaje
 *   RealtimeEventPublisher::tripUpdate($tripId, [
 *       'status' => 'en_curso',
 *       'lat' => 4.123,
 *       'lng' => -74.456,
 *   ]);
 *
 *   // Evento para usuario específico
 *   RealtimeEventPublisher::toUser($userId, 'trip.assigned', [
 *       'trip_id' => 123,
 *       'driver_name' => 'Juan',
 *   ]);
 *
 * Canales:
 *   viax:user:{id}      → pasajero
 *   viax:driver:{id}    → conductor
 *   viax:trip:{id}      → viaje
 *   viax:request:{id}   → solicitud de servicio
 *   viax:chat:{id}      → chat de viaje
 *
 * @author Viax Platform
 */

class RealtimeEventPublisher
{
    /** Prefijo de canal para el gateway */
    private const CHANNEL_PREFIX = 'viax:';
    private const EVENTS_CHANNEL = 'viax:events';

    /** @var object|null Conexión Redis reutilizable dentro del request */
    private static $redis = null;

    /** @var bool Flag para evitar reintentos infinitos si Redis no está disponible */
    private static bool $disabled = false;

    // ─── Métodos públicos de publicación ────────────────────────────

    /**
     * Publica evento a un usuario (pasajero).
     */
    public static function toUser(int $userId, string $eventType, array $payload = []): bool
    {
        return self::publish([$eventType], 'user', (string)$userId, $payload, ["user:{$userId}"]);
    }

    /**
     * Publica evento a un conductor.
     */
    public static function toDriver(int $driverId, string $eventType, array $payload = []): bool
    {
        return self::publish([$eventType], 'driver', (string)$driverId, $payload, ["driver:{$driverId}"]);
    }

    /**
     * Publica evento de viaje (tracking, estado, métricas).
     */
    public static function tripUpdate(int $tripId, array $payload = [], string $eventType = 'trip.location_updated'): bool
    {
        return self::publish([$eventType], 'trip', (string)$tripId, $payload, ["trip:{$tripId}"]);
    }

    /**
     * Publica evento de solicitud (búsqueda, asignación, cancelación).
     */
    public static function requestUpdate(int $requestId, array $payload = [], string $eventType = 'request.status_changed'): bool
    {
        return self::publish([$eventType], 'request', (string)$requestId, $payload, ["request:{$requestId}"]);
    }

    /**
     * Publica mensaje de chat en tiempo real.
     */
    public static function chatMessage(int $tripId, array $payload = []): bool
    {
        return self::publish(['chat.message'], 'chat', (string)$tripId, $payload, ["chat:{$tripId}"]);
    }

    // ─── Eventos de negocio específicos ─────────────────────────────

    /**
     * Conductor asignado a solicitud.
     * Notifica al usuario Y al canal de la solicitud.
     */
    public static function driverAssigned(int $requestId, int $userId, int $driverId, array $driverInfo = []): void
    {
        $payload = array_merge([
            'request_id' => $requestId,
            'driver_id' => $driverId,
            'user_id' => $userId,
        ], $driverInfo);

        self::publish(
            ['trip.assigned'],
            'trip',
            (string)($payload['trip_id'] ?? $requestId),
            $payload,
            [
                "request:{$requestId}",
                "user:{$userId}",
                "driver:{$driverId}",
            ]
        );
    }

    /**
     * Estado de búsqueda cambiado (sin_conductores, timeout, etc).
     */
    public static function searchStatusChanged(int $requestId, int $userId, string $status, array $extra = []): void
    {
        $payload = array_merge([
            'request_id' => $requestId,
            'status' => $status,
        ], $extra);

        self::publish(
            ['request.status_changed'],
            'request',
            (string)$requestId,
            $payload,
            ["request:{$requestId}", "user:{$userId}"]
        );
    }

    /**
     * Oferta de viaje enviada a conductor.
     */
    public static function tripOfferSent(int $requestId, int $driverId, array $offerDetails = []): void
    {
        $payload = array_merge([
            'request_id' => $requestId,
            'driver_id' => $driverId,
        ], $offerDetails);

        self::publish(
            ['request.new'],
            'request',
            (string)$requestId,
            $payload,
            ["request:{$requestId}", "driver:{$driverId}"]
        );
    }

    /**
     * Actualización de tracking en tiempo real durante viaje.
     */
    public static function trackingUpdate(int $tripId, int $userId, array $trackingData = []): void
    {
        $payload = array_merge(['trip_id' => $tripId], $trackingData);

        self::publish(
            ['trip.location_updated'],
            'trip',
            (string)$tripId,
            $payload,
            ["trip:{$tripId}", "user:{$userId}"]
        );
    }

    /**
     * Estado de viaje cambiado (recogido, en_curso, completada, etc).
     */
    public static function tripStatusChanged(int $tripId, int $userId, string $status, array $extra = []): void
    {
        $payload = array_merge([
            'trip_id' => $tripId,
            'status' => $status,
        ], $extra);

        $type = ($status === 'cancelada' || $status === 'cancelado')
            ? 'trip.cancelled'
            : 'trip.status_changed';

        self::publish(
            [$type],
            'trip',
            (string)$tripId,
            $payload,
            ["trip:{$tripId}", "user:{$userId}"]
        );
    }

    /**
     * Nuevo mensaje de chat.
     */
    public static function newChatMessage(int $tripId, int $senderId, int $receiverId, array $messageData = []): void
    {
        $payload = array_merge([
            'trip_id' => $tripId,
            'sender_id' => $senderId,
        ], $messageData);

        self::publish(
            ['chat.message'],
            'chat',
            (string)$tripId,
            $payload,
            ["chat:{$tripId}", "user:{$receiverId}"]
        );
    }

    // ─── Núcleo de publicación ──────────────────────────────────────

    /**
     * Publica un evento JSON al canal Redis del gateway.
     *
    * @param array  $eventTypes Tipos de evento (ej: ["trip.location_updated"])
     * @param string $entity    Entidad (user|driver|trip|request|chat)
     * @param string $entityId  ID de la entidad
     * @param array  $payload   Datos del evento
    * @param array  $channels  Canales destino sin prefijo global (ej: ["trip:123"])
     * @return bool true si se publicó exitosamente
     */
    private static function publish(array $eventTypes, string $entity, string $entityId, array $payload, array $channels): bool
    {
        if (self::$disabled) {
            return false;
        }

        try {
            $redis = self::getRedis();
            if (!$redis) {
                return false;
            }

            $published = false;
            $timestamp = time();
            $safeChannels = array_values(array_unique(array_filter($channels, static fn($c) => is_string($c) && $c !== '')));

            foreach ($eventTypes as $eventType) {
                if (!is_string($eventType) || $eventType === '') {
                    continue;
                }

                $event = [
                    'type' => $eventType,
                    'version' => 1,
                    'entity' => $entity,
                    'entity_id' => $entityId,
                    'timestamp' => $timestamp,
                    'event_id' => self::buildEventId($eventType, $entity, $entityId, $payload, $timestamp),
                    'channels' => $safeChannels,
                    'payload' => $payload,
                ];

                $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($json) || $json === '') {
                    continue;
                }

                $redis->publish(self::EVENTS_CHANNEL, $json);
                $published = true;
            }

            if (!$published) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[RealtimePublisher] Error publicando evento realtime: ' . $e->getMessage());
            return false;
        }
    }

    private static function buildEventId(string $eventType, string $entity, string $entityId, array $payload, int $timestamp): string
    {
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        return $eventType . ':' . $entity . ':' . $entityId . ':' . $timestamp . ':' . substr($payloadHash, 0, 16);
    }

    // ─── Conexión Redis ─────────────────────────────────────────────

    /**
     * Obtiene o crea conexión Redis para publicación.
     * Usa conexión independiente del Cache global para no interferir.
     */
    private static function getRedis()
    {
        if (self::$redis !== null) {
            try {
                self::$redis->ping();
                return self::$redis;
            } catch (\Throwable $e) {
                self::$redis = null;
            }
        }

        try {
            $hostDefault = defined('REDIS_HOST') ? (string) constant('REDIS_HOST') : '127.0.0.1';
            $portDefault = defined('REDIS_PORT') ? (int) constant('REDIS_PORT') : 6379;
            $passwordDefault = defined('REDIS_PASSWORD') ? (string) constant('REDIS_PASSWORD') : null;

            $host = getenv('REDIS_HOST') ?: $hostDefault;
            $port = (int) (getenv('REDIS_PORT') ?: $portDefault);
            $password = getenv('REDIS_PASSWORD') ?: $passwordDefault;

            if (!class_exists('Redis')) {
                self::$disabled = true;
                error_log('[RealtimePublisher] Extensión Redis no disponible (clase Redis no encontrada)');
                return null;
            }

            $redisClass = 'Redis';
            $redis = new $redisClass();
            $connected = $redis->connect($host, $port, 0.5); // timeout 500ms

            if (!$connected) {
                self::$disabled = true;
                return null;
            }

            if ($password) {
                $redis->auth($password);
            }

            self::$redis = $redis;
            return self::$redis;
        } catch (\Throwable $e) {
            error_log('[RealtimePublisher] No se pudo conectar a Redis: ' . $e->getMessage());
            // Deshabilitar para este request para no reintentar
            self::$disabled = true;
            return null;
        }
    }

    /**
     * Resetea el estado (útil en tests o al iniciar worker).
     */
    public static function reset(): void
    {
        self::$redis = null;
        self::$disabled = false;
    }
}
