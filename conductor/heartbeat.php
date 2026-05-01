<?php
/**
 * Endpoint: heartbeat de conductor.
 *
 * Flujo:
 * 1) Validar sesión de conductor.
 * 2) Actualizar clave driver:heartbeat:{driver_id}.
 * 3) Refrescar TTL de heartbeat.
 * 4) Mantener conductor en set de disponibles.
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Driver-Session');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/driver_service.php';
require_once __DIR__ . '/driver_auth.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $conductorId = isset($input['conductor_id']) ? (int)$input['conductor_id'] : 0;
    if ($conductorId <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    $sessionToken = driverSessionTokenFromRequest($input);
    $session = validateDriverSession($conductorId, $sessionToken, false);
    if (!$session['ok']) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => $session['message'],
        ]);
        exit();
    }

    // Heartbeat de 20s: la app debe enviarlo cada ~10s.
    DriverGeoService::touchDriverHeartbeat($conductorId, 20);

    // Mantener conductor online en la malla/sets disponibles.
    DriverGeoService::setDriverState($conductorId, 'available');

    echo json_encode([
        'success' => true,
        'message' => 'Heartbeat actualizado',
        'session_token' => $session['session_token'],
        'heartbeat_ttl_sec' => 20,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
