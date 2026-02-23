<?php
/**
 * Location Sharing API — Create Share Session
 * 
 * POST /location_sharing/create_share.php
 * Body: { user_id, solicitud_id?, expires_minutes? }
 * 
 * Returns a unique token with a share URL.
 */
require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, 'Método no permitido', [], 405);
}

$input = getJsonInput();
$userId = intval($input['user_id'] ?? 0);
$solicitudId = isset($input['solicitud_id']) ? intval($input['solicitud_id']) : null;
$expiresMinutes = intval($input['expires_minutes'] ?? 120);

if ($userId <= 0) {
    sendJsonResponse(false, 'user_id es obligatorio', [], 400);
}

// Clamp expiry between 15 min and 24 h
$expiresMinutes = max(15, min($expiresMinutes, 1440));

try {
    $db = (new Database())->getConnection();

    // Deactivate any previous active share for this user
    $stmt = $db->prepare("UPDATE location_share_tokens SET is_active = false WHERE user_id = :uid AND is_active = true");
    $stmt->execute([':uid' => $userId]);

    // Generate a cryptographically secure token
    $token = bin2hex(random_bytes(24)); // 48-char hex

    // Fetch user name and photo for the share metadata
    $nameStmt = $db->prepare("SELECT nombre, foto_perfil FROM usuarios WHERE id = :uid LIMIT 1");
    $nameStmt->execute([':uid' => $userId]);
    $userRow = $nameStmt->fetch();
    $sharerName = $userRow ? $userRow['nombre'] : null;
    $sharerPhoto = $userRow ? $userRow['foto_perfil'] : null;

    // If linked to a trip, fetch destination info
    $destAddress = null;
    $destLat = null;
    $destLng = null;
    $vehiclePlate = null;
    $vehicleInfo = null;

    if ($solicitudId) {
        $tripStmt = $db->prepare("
            SELECT s.direccion_destino, s.latitud_destino, s.longitud_destino,
                   dc.vehiculo_placa,
                   CONCAT(dc.vehiculo_marca, ' ', dc.vehiculo_modelo) AS vehicle_info
            FROM solicitudes_servicio s
            LEFT JOIN detalles_conductor dc ON dc.usuario_id = s.conductor_id
            WHERE s.id = :sid
            LIMIT 1
        ");
        $tripStmt->execute([':sid' => $solicitudId]);
        $trip = $tripStmt->fetch();
        if ($trip) {
            $destAddress = $trip['direccion_destino'];
            $destLat = $trip['latitud_destino'] ? floatval($trip['latitud_destino']) : null;
            $destLng = $trip['longitud_destino'] ? floatval($trip['longitud_destino']) : null;
            $vehiclePlate = $trip['vehiculo_placa'];
            $vehicleInfo = $trip['vehicle_info'];
        }
    }

    // Insert share session
    $stmt = $db->prepare("
        INSERT INTO location_share_tokens
            (token, user_id, solicitud_id, nombre_usuario, foto_usuario, vehicle_info, vehicle_plate,
             destination_address, destination_lat, destination_lng,
             expires_at)
        VALUES
            (:token, :uid, :sid, :nombre, :foto, :vinfo, :plate,
             :dest_addr, :dest_lat, :dest_lng,
             NOW() + INTERVAL '$expiresMinutes minutes')
        RETURNING id, token, expires_at
    ");
    $stmt->execute([
        ':token'     => $token,
        ':uid'       => $userId,
        ':sid'       => $solicitudId,
        ':nombre'    => $sharerName,
        ':foto'      => $sharerPhoto,
        ':vinfo'     => $vehicleInfo,
        ':plate'     => $vehiclePlate,
        ':dest_addr' => $destAddress,
        ':dest_lat'  => $destLat,
        ':dest_lng'  => $destLng,
    ]);

    $row = $stmt->fetch();

    sendJsonResponse(true, 'Sesión de compartir creada', [
        'id'         => intval($row['id']),
        'token'      => $row['token'],
        'expires_at' => $row['expires_at'],
    ]);

} catch (Exception $e) {
    sendJsonResponse(false, 'Error creando sesión de compartir: ' . $e->getMessage(), [], 500);
}
