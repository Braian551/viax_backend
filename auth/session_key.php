<?php
/**
 * Endpoint: POST /auth/session_key.php
 * 
 * Genera una session_key temporal asociada a user_id + device_id.
 * La clave se almacena HASHEADA en Redis y se retorna al cliente en texto plano.
 * Esto evita que el secreto HMAC esté hardcodeado en el APK.
 * 
 * Protecciones:
 * - Rate limit por IP + user_id
 * - TTL configurable desde .env (SESSION_KEY_TTL, default 300s)
 * - Solo almacena el HASH de la session_key en Redis
 * - Logging seguro (sin claves)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-Fingerprint');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/app.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die(json_encode(['success' => false, 'message' => 'Método no permitido']));
    }

    // --- Rate limit por IP antes de procesar ---
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        if (class_exists('Cache')) {
            $redis = Cache::redis();
            if ($redis) {
                $rlKey = "rate_limit:session_key:ip:{$ip}";
                $rlCount = $redis->incr($rlKey);
                if ($rlCount === 1) $redis->expire($rlKey, 60);
                if ($rlCount > 30) { // Máximo 30 session keys por minuto por IP
                    http_response_code(429);
                    die(json_encode(['success' => false, 'message' => 'Demasiadas solicitudes de session key.']));
                }
            }
        }
    } catch (Throwable $e) {
        // Fail-safe: continuar sin rate limit si Redis falla
    }

    $rawData = file_get_contents('php://input');
    $input = json_decode($rawData, true);
    
    if (!is_array($input)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Invalid JSON body']));
    }
    
    $userId = intval($input['user_id'] ?? 0);
    $deviceId = trim($input['device_id'] ?? '');
    $fingerprint = $_SERVER['HTTP_X_DEVICE_FINGERPRINT'] ?? '';

    if (!$userId || !$deviceId) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'user_id y device_id son requeridos']));
    }

    // --- Rate limit por user_id ---
    try {
        if (class_exists('Cache')) {
            $redis = Cache::redis();
            if ($redis) {
                $rlUserKey = "rate_limit:session_key:user:{$userId}";
                $rlUserCount = $redis->incr($rlUserKey);
                if ($rlUserCount === 1) $redis->expire($rlUserKey, 60);
                if ($rlUserCount > 15) { // Máximo 15 renovaciones por minuto por usuario
                    http_response_code(429);
                    die(json_encode(['success' => false, 'message' => 'Demasiadas renovaciones de session key.']));
                }
            }
        }
    } catch (Throwable $e) {}

    // Verificar que el usuario existe en la BD
    $database = new Database();
    $db = $database->getConnection();
    $stmt = $db->prepare("SELECT id, tipo_usuario FROM usuarios WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'Usuario no encontrado']));
    }

    // Generar session_key aleatoria y segura (NO derivada de ningún secreto)
    $sessionKey = bin2hex(random_bytes(32)); // 64 caracteres hex
    $ttl = intval(env_value('SESSION_KEY_TTL', 300)); // Default: 5 minutos
    $expiresAt = time() + $ttl;

    // Almacenar en Redis: guardar HASH de la session_key, no la clave en texto plano
    $prefix = env_value('REDIS_PREFIX_SECURITY', 'session');
    $redisKey = "{$prefix}:{$userId}:{$deviceId}";
    $sessionData = json_encode([
        'key_hash' => hash('sha256', $sessionKey), // Solo el hash se guarda
        'device_id' => $deviceId,
        'fingerprint' => $fingerprint,
        'user_type' => $user['tipo_usuario'],
        'created_at' => time(),
        'expires_at' => $expiresAt,
    ]);

    $stored = false;
    try {
        if (class_exists('Cache')) {
            $redis = Cache::redis();
            if ($redis) {
                $redis->setex($redisKey, $ttl, $sessionData);
                $stored = true;
            }
        }
    } catch (Throwable $e) {
        error_log("[SessionKey] Error Redis: " . $e->getMessage());
    }

    if (!$stored) {
        error_log("[SessionKey] Redis no disponible. Session key generada sin cache.");
    }

    // Log de auditoría (sin incluir la clave ni su hash)
    error_log("[SessionKey] Generada para user_id={$userId}, expira=" . date('H:i:s', $expiresAt));

    echo json_encode([
        'success' => true,
        'session_key' => $sessionKey, // Solo aquí se retorna en texto plano
        'expires_at' => $expiresAt,
        'ttl_seconds' => $ttl,
    ]);

} catch (Throwable $e) {
    error_log("[SessionKey] Error crítico: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
}
