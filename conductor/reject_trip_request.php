<?php
header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../config/redis.php';
require_once '../core/Cache.php';
require_once '../services/driver_service.php';
require_once __DIR__ . '/driver_auth.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    if (!isset($data['solicitud_id']) || !isset($data['conductor_id'])) {
        throw new Exception('Datos requeridos: solicitud_id, conductor_id');
    }
    
    $solicitudId = (int) $data['solicitud_id'];
    $conductorId = (int) $data['conductor_id'];
    $motivo = trim((string) ($data['motivo'] ?? 'Conductor rechazó'));

    if ($solicitudId <= 0 || $conductorId <= 0) {
        throw new Exception('solicitud_id y conductor_id inválidos');
    }

    // Validar sesión para evitar rechazos emitidos por sesiones huérfanas.
    $sessionToken = driverSessionTokenFromRequest($data);
    $session = validateDriverSession($conductorId, $sessionToken, false);
    if (!$session['ok']) {
        throw new Exception($session['message']);
    }
    DriverGeoService::touchDriverHeartbeat($conductorId, 20);
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Registrar rechazo (idempotente): evita duplicados por reintentos móviles.
    $stmt = $db->prepare(" 
        INSERT INTO rechazos_conductor (
            solicitud_id,
            conductor_id,
            motivo,
            fecha_rechazo
        ) VALUES (?, ?, ?, NOW())
        ON CONFLICT (solicitud_id, conductor_id) DO NOTHING
    ");
    $stmt->execute([$solicitudId, $conductorId, $motivo]);

    // Cache auxiliar para que matching no vuelva a sugerir este conductor.
    Cache::set('trip_rejected:' . $solicitudId . ':' . $conductorId, '1', 600);

    try {
        $redis = Cache::redis();
        if ($redis) {
            // Cooldown inmediato tras rechazo explícito.
            $redis->setex('driver:cooldown:' . $conductorId, 30, '1');
            $redis->del('driver_offer_lock:' . $conductorId);
            $redis->setex('ride:' . $solicitudId . ':driver:' . $conductorId . ':status', 180, 'rejected');
            $currentDriverRaw = $redis->get('ride:' . $solicitudId . ':current_driver');
            if (is_string($currentDriverRaw) && (int)$currentDriverRaw === $conductorId) {
                $redis->del('ride:' . $solicitudId . ':current_driver');
            }

            $payload = json_encode([
                'driver_id' => $conductorId,
                'status' => 'rejected',
                'reason' => $motivo,
                'rejected_at' => gmdate('c'),
            ], JSON_UNESCAPED_UNICODE);

            $redis->publish('trip:responses:' . $solicitudId, $payload);
            $redis->lPush('trip:responses_queue:' . $solicitudId, $payload);
            $redis->expire('trip:responses_queue:' . $solicitudId, 120);
            $redis->incr('metrics:driver_rejections');

            DriverGeoService::updateDriverStats($conductorId, null, 1.0, null);
        }
    } catch (Throwable $e) {}
    
    echo json_encode([
        'success' => true,
        'message' => 'Solicitud rechazada'
    ]);
    
} catch (Exception $e) {
    // Si la tabla no existe en algún entorno, mantener compatibilidad devolviendo éxito.
    if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), 'relation "rechazos_conductor" does not exist') !== false) {
        echo json_encode([
            'success' => true,
            'message' => 'Solicitud rechazada (registro no guardado)'
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
