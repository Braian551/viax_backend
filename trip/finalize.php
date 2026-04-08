<?php
/**
 * Wrapper de compatibilidad para finalizar viaje.
 *
 * Mantiene la API legacy (`conductor/tracking/finalize.php`) como
 * implementación principal y expone una ruta semántica nueva:
 * POST /trip/finalize
 */

header('Content-Type: application/json');
// CORS seguro: solo dominios controlados; requests sin Origin (app movil) se permiten.
$allowedOrigins = [
    'https://viaxcol.online',
    'https://www.viaxcol.online',
];
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif ($origin !== '') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CORS blocked']);
    exit();
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON inválido']);
    exit();
}

// Adaptación de nombres sin romper contrato existente.
if (!isset($payload['solicitud_id']) && isset($payload['trip_id'])) {
    $payload['solicitud_id'] = intval($payload['trip_id']);
}

if (!isset($payload['conductor_id']) && isset($payload['driver_id'])) {
    $payload['conductor_id'] = intval($payload['driver_id']);
}

if (!isset($payload['distancia_final_km']) && isset($payload['distance_km'])) {
    $payload['distancia_final_km'] = floatval($payload['distance_km']);
}

if (!isset($payload['tiempo_final_seg']) && isset($payload['duration_sec'])) {
    $payload['tiempo_final_seg'] = intval($payload['duration_sec']);
}

// Compatibilidad: si no llegó conductor_id, intentamos resolverlo por asignación activa.
if ((intval($payload['conductor_id'] ?? 0) <= 0) && intval($payload['solicitud_id'] ?? 0) > 0) {
    require_once __DIR__ . '/../config/database.php';
    try {
        $database = new Database();
        $db = $database->getConnection();
        $stmt = $db->prepare(" 
            SELECT conductor_id
            FROM asignaciones_conductor
            WHERE solicitud_id = :solicitud_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':solicitud_id' => intval($payload['solicitud_id'])]);
        $payload['conductor_id'] = intval($stmt->fetchColumn() ?: 0);
    } catch (Throwable $_) {
        // Se deja en 0 para que el endpoint legacy devuelva error controlado.
    }
}

// Reinyecta payload normalizado para que finalize legacy lo consuma.
$GLOBALS['__trip_finalize_payload_bridge'] = $payload;

require_once __DIR__ . '/../conductor/tracking/finalize.php';
