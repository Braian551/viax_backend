<?php
/**
 * Location Sharing API — Update Location
 * 
 * POST /location_sharing/update_location.php
 * Body: { token, latitude, longitude, heading?, speed?, accuracy? }
 * 
 * Called periodically by the sharer's device to push GPS coordinates.
 */
require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, 'Método no permitido', [], 405);
}

$input = getJsonInput();
$token     = $input['token']     ?? '';
$latitude  = floatval($input['latitude']  ?? 0);
$longitude = floatval($input['longitude'] ?? 0);
$heading   = floatval($input['heading']   ?? 0);
$speed     = floatval($input['speed']     ?? 0);
$accuracy  = floatval($input['accuracy']  ?? 0);

if (empty($token) || ($latitude == 0 && $longitude == 0)) {
    sendJsonResponse(false, 'token, latitude y longitude son obligatorios', [], 400);
}

try {
    $db = (new Database())->getConnection();

    $stmt = $db->prepare("
        UPDATE location_share_tokens
        SET latitud      = :lat,
            longitud      = :lng,
            heading       = :heading,
            speed         = :speed,
            accuracy      = :accuracy,
            last_updated  = NOW()
        WHERE token = :token
          AND is_active = true
          AND expires_at > NOW()
    ");
    $stmt->execute([
        ':lat'      => $latitude,
        ':lng'      => $longitude,
        ':heading'  => $heading,
        ':speed'    => $speed,
        ':accuracy' => $accuracy,
        ':token'    => $token,
    ]);

    if ($stmt->rowCount() === 0) {
        sendJsonResponse(false, 'Sesión no encontrada, expirada o inactiva', [], 404);
    }

    sendJsonResponse(true, 'Ubicación actualizada');

} catch (Exception $e) {
    sendJsonResponse(false, 'Error actualizando ubicación: ' . $e->getMessage(), [], 500);
}
