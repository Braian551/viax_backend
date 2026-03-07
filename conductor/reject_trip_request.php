<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../config/redis.php';
require_once '../core/Cache.php';

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
