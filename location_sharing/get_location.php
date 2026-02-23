<?php
/**
 * Location Sharing API — Get Location (polling endpoint)
 * 
 * GET /location_sharing/get_location.php?token=xxx
 * 
 * Used by viewers (web or app) to poll the sharer's current position.
 * Returns location data + trip metadata.
 */
require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(false, 'Método no permitido', [], 405);
}

$token = $_GET['token'] ?? '';

if (empty($token)) {
    sendJsonResponse(false, 'El parámetro token es obligatorio', [], 400);
}

try {
    $db = (new Database())->getConnection();

    // Auto-deactivate expired sessions
    $db->exec("UPDATE location_share_tokens SET is_active = false WHERE expires_at < NOW() AND is_active = true");

    $stmt = $db->prepare("
        SELECT ls.id, ls.token, ls.user_id, ls.solicitud_id,
               ls.latitud AS latitude, ls.longitud AS longitude,
               ls.heading, ls.speed, ls.accuracy,
               ls.last_updated AS last_update, ls.created_at, ls.expires_at, ls.is_active,
               ls.nombre_usuario AS sharer_name, ls.foto_usuario AS sharer_photo,
               ls.vehicle_plate, ls.vehicle_info,
               ls.destination_address, ls.destination_lat, ls.destination_lng,
               u.nombre AS user_name, u.foto_perfil AS user_photo
        FROM location_share_tokens ls
        LEFT JOIN usuarios u ON u.id = ls.user_id
        WHERE ls.token = :token
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $share = $stmt->fetch();

    if (!$share) {
        sendJsonResponse(false, 'Enlace de compartir no encontrado', [], 404);
    }

    if (!$share['is_active']) {
        sendJsonResponse(false, 'La sesión de compartir ha finalizado', [
            'expired' => true,
            'sharer_name' => $share['sharer_name'] ?? $share['user_name'],
        ], 410);
    }

    // Check expiry
    $expiresAt = new DateTime($share['expires_at']);
    $now = new DateTime('now', new DateTimeZone('UTC'));
    if ($expiresAt < $now) {
        // Mark as inactive
        $db->prepare("UPDATE location_share_tokens SET is_active = false WHERE id = :id")
           ->execute([':id' => $share['id']]);
        sendJsonResponse(false, 'La sesión de compartir ha expirado', [
            'expired' => true,
            'sharer_name' => $share['sharer_name'] ?? $share['user_name'],
        ], 410);
    }

    // Calculate remaining time
    $remainingSeconds = $expiresAt->getTimestamp() - $now->getTimestamp();

    // Resolve photo — return r2_proxy relative path for non-http keys
    $photoKey = $share['sharer_photo'] ?? $share['user_photo'];
    $photoUrl = null;
    if ($photoKey) {
        if (str_starts_with($photoKey, 'http')) {
            $photoUrl = $photoKey;
        } else {
            // Return relative proxy path — clients prepend their own base URL
            $photoUrl = "r2_proxy.php?key=" . urlencode($photoKey);
        }
    }

    // Build response
    $data = [
        'token'               => $share['token'],
        'is_active'           => true,
        'latitude'            => $share['latitude'] ? floatval($share['latitude']) : null,
        'longitude'           => $share['longitude'] ? floatval($share['longitude']) : null,
        'heading'             => floatval($share['heading']),
        'speed'               => floatval($share['speed']),
        'accuracy'            => floatval($share['accuracy']),
        'last_update'         => $share['last_update'],
        'sharer_name'         => $share['sharer_name'] ?? $share['user_name'],
        'sharer_photo'        => $photoUrl,
        'vehicle_plate'       => $share['vehicle_plate'],
        'vehicle_info'        => $share['vehicle_info'],
        'destination_address' => $share['destination_address'],
        'destination_lat'     => $share['destination_lat'] ? floatval($share['destination_lat']) : null,
        'destination_lng'     => $share['destination_lng'] ? floatval($share['destination_lng']) : null,
        'expires_at'          => $share['expires_at'],
        'remaining_seconds'   => max(0, $remainingSeconds),
    ];

    sendJsonResponse(true, 'Ubicación obtenida', $data);

} catch (Exception $e) {
    sendJsonResponse(false, 'Error obteniendo ubicación: ' . $e->getMessage(), [], 500);
}
