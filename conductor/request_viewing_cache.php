<?php

require_once __DIR__ . '/../core/Cache.php';

const LEGACY_DRIVER_VIEWING_TTL_SEC = 12;

function legacyDriversViewingListKey(int $requestId): string
{
    return 'ride:' . $requestId . ':drivers_viewing';
}

function legacyDriverViewingPayloadKey(int $requestId, int $driverId): string
{
    return 'ride:' . $requestId . ':drivers_viewing:driver:' . $driverId;
}

function legacyNormalizeDriverDisplayName(array $driverContext): string
{
    $nombre = trim((string)($driverContext['nombre'] ?? ''));
    $apellido = trim((string)($driverContext['apellido'] ?? ''));
    $full = trim($nombre . ' ' . $apellido);
    return $full !== '' ? $full : 'Conductor';
}

function legacyEstimateEtaMinutes(?float $distanceKm): int
{
    if (!is_numeric($distanceKm)) {
        return 3;
    }

    $km = max(0.0, (float)$distanceKm);
    return max(1, min(15, (int)ceil($km * 4.0)));
}

function publishLegacyDriverViewingState(int $requestId, array $driverContext, ?float $distanceKmToPickup = null, int $ttl = LEGACY_DRIVER_VIEWING_TTL_SEC): void
{
    $driverId = (int)($driverContext['id'] ?? $driverContext['conductor_id'] ?? 0);
    if ($requestId <= 0 || $driverId <= 0) {
        return;
    }

    $redis = Cache::redis();
    if (!$redis) {
        return;
    }

    $driverName = legacyNormalizeDriverDisplayName($driverContext);
    $payload = [
        'driver_id' => $driverId,
        'name' => $driverName,
        'eta_minutes' => legacyEstimateEtaMinutes($distanceKmToPickup),
        'distance_km' => $distanceKmToPickup !== null
            ? round(max(0.0, $distanceKmToPickup), 2)
            : null,
        'updated_at' => function_exists('now_colombia')
            ? now_colombia()->format('c')
            : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c'),
    ];

    $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($serialized) || $serialized === '') {
        return;
    }

    $listKey = legacyDriversViewingListKey($requestId);
    $payloadKey = legacyDriverViewingPayloadKey($requestId, $driverId);

    $previous = $redis->get($payloadKey);
    if (is_string($previous) && $previous !== '') {
        $redis->lRem($listKey, 0, $previous);
    }

    $redis->lPush($listKey, $serialized);
    $redis->lTrim($listKey, 0, 9);
    $redis->expire($listKey, $ttl);
    $redis->setex($payloadKey, $ttl, $serialized);

    $currentDriverKey = 'ride:' . $requestId . ':current_driver';
    $currentDriverRaw = $redis->get($currentDriverKey);
    $currentDriverId = is_string($currentDriverRaw) ? (int)$currentDriverRaw : 0;
    $currentDriverStatus = '';

    if ($currentDriverId > 0) {
        $currentStatusRaw = $redis->get('ride:' . $requestId . ':driver:' . $currentDriverId . ':status');
        $currentDriverStatus = strtolower(trim((string)$currentStatusRaw));
    }

    if ($currentDriverId <= 0 || $currentDriverId === $driverId || !in_array($currentDriverStatus, ['pending', 'offered', 'checking', 'accepted'], true)) {
        $redis->setex($currentDriverKey, $ttl, (string)$driverId);
        $redis->setex('ride:' . $requestId . ':driver:' . $driverId . ':status', $ttl, 'checking');
    }

    $matchingStatusRaw = strtolower(trim((string)$redis->get('ride:' . $requestId . ':matching_status')));
    if (!in_array($matchingStatusRaw, ['matched', 'accepted', 'cancelada', 'cancelled', 'completed', 'completada'], true)) {
        $redis->setex('ride:' . $requestId . ':matching_status', $ttl, 'driver_viewing');
    }

    $redis->setex('ride:' . $requestId . ':ui_message', $ttl, $driverName . ' esta revisando tu solicitud');
}
