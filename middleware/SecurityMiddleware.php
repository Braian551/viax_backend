<?php

/**
 * SecurityMiddleware v2 — Middleware de seguridad con Soft Enforcement.
 * 
 * Características:
 * - Secretos desde variables de entorno (env_value)
 * - Session keys dinámicas (Redis TTL 5min)
 * - Modo "monitor" (solo logs) vs "enforce" (bloqueo real)
 * - Rate limiting por IP y dispositivo
 * - Nonce anti-replay
 * - Detección multi-cuenta
 * - Logging seguro (sin tokens/claves)
 */

require_once __DIR__ . '/../config/app.php';

class SecurityMiddleware
{
    // Tolerancia de timestamp: 5 minutos (en milisegundos)
    private const TIMESTAMP_TOLERANCE_MS = 300000;
    
    // Rate limiting
    private const RATE_LIMIT_PER_IP = 60;
    private const RATE_LIMIT_PER_DEVICE = 120;
    private const RATE_WINDOW_SECONDS = 60;

    /**
     * Obtiene el modo de enforcement actual.
     * Valores: "monitor" (solo logs), "partial" (bloquea nuevos), "enforce" (bloqueo total).
     */
    private static function getEnforcementMode(): string
    {
        return env_value('SECURITY_ENFORCEMENT_MODE', 'monitor');
    }

    /**
     * Obtiene el secreto HMAC fallback desde variables de entorno.
     */
    private static function getHmacSecret(): string
    {
        $secret = env_value('HMAC_SECRET', '');
        if (empty($secret)) {
            error_log('[SecurityMiddleware] ⚠️ HMAC_SECRET no configurado en .env');
        }
        return $secret;
    }

    /**
     * Obtiene el salt de fingerprint desde variables de entorno.
     */
    private static function getFingerprintSalt(): string
    {
        $salt = env_value('FINGERPRINT_SALT', '');
        if (empty($salt)) {
            error_log('[SecurityMiddleware] ⚠️ FINGERPRINT_SALT no configurado en .env');
        }
        return $salt;
    }

    /**
     * Valida la session_key del usuario contra Redis.
     * Compara el HASH de la session_key recibida con el HASH almacenado.
     * Retorna la session_key si es válida (para usarla como clave HMAC).
     */
    public static function validateSessionKey(int $userId, string $deviceId): ?string
    {
        $sessionKeyHeader = $_SERVER['HTTP_X_SESSION_KEY'] ?? null;
        if (!$sessionKeyHeader) {
            return null; // No se envió session_key
        }

        $fingerprint = $_SERVER['HTTP_X_DEVICE_FINGERPRINT'] ?? '';

        try {
            if (!class_exists('Cache')) return null;
            $redis = Cache::redis();
            if (!$redis) return null;

            $prefix = env_value('REDIS_PREFIX_SECURITY', 'session');

            // Búsqueda exacta (SIN WILDCARD)
            $redisKey = "{$prefix}:{$userId}:{$deviceId}";
            $stored = $redis->get($redisKey);
            
            if (!$stored) {
                error_log("[SecurityMiddleware] Session key expirada o inexistente exact key={$redisKey}");
                return null;
            }

            // Calcular hash de la session_key recibida
            $receivedHash = hash('sha256', $sessionKeyHeader);

            $data = json_decode($stored, true);
            if (!$data) return null;

            // Comparar hashes de forma segura (timing-safe)
            if (hash_equals($data['key_hash'] ?? '', $receivedHash)) {
                // Verificar fingerprint si ambos están disponibles
                if (!empty($data['fingerprint']) && !empty($fingerprint)) {
                    if ($data['fingerprint'] !== $fingerprint) {
                        error_log("[SecurityMiddleware] Fingerprint NO coincide para user_id={$userId}");
                        return null;
                    }
                }
                return $sessionKeyHeader; // Válida
            }

            error_log("[SecurityMiddleware] Session key inválida para user_id={$userId}");
            return null;
        } catch (Throwable $e) {
            error_log("[SecurityMiddleware] Error validando session_key: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Valida la firma HMAC usando session_key dinámica o secreto estático como fallback.
     * El payload ahora debe incluir user_id y device_id
     */
    public static function validateHmacSignature(string $requestPath, string $requestBody, int $userId, string $deviceId, ?string $sessionKey = null): bool
    {
        $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? null;
        $nonce = $_SERVER['HTTP_X_NONCE'] ?? null;
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? null;

        if (!$timestamp || !$nonce || !$signature) {
            error_log('[SecurityMiddleware] Request sin headers HMAC.');
            return false;
        }

        // Protección contra replay attacks
        $currentMs = round(microtime(true) * 1000);
        $requestMs = intval($timestamp);
        $diff = abs($currentMs - $requestMs);

        if ($diff > self::TIMESTAMP_TOLERANCE_MS) {
            error_log("[SecurityMiddleware] REPLAY ATTACK potencial. Diferencia: {$diff}ms");
            return false;
        }

        // Verificar nonce no repetido
        if (self::isNonceUsed($nonce)) {
            error_log("[SecurityMiddleware] NONCE REUTILIZADO");
            return false;
        }
        self::markNonceUsed($nonce);

        // Determinar la clave de firma: session_key dinámica o fallback estático
        $signingKey = $sessionKey ?? self::getHmacSecret();
        if (empty($signingKey)) {
            error_log("[SecurityMiddleware] Sin clave de firma disponible");
            return false;
        }

        // Recalcular firma esperada (payload extendido)
        $dataToSign = "$requestPath|$requestBody|$timestamp|$nonce|$userId|$deviceId";
        $expectedSignature = hash_hmac('sha256', $dataToSign, $signingKey);

        if (!hash_equals($expectedSignature, $signature)) {
            // No loggear las firmas completas por seguridad
            error_log("[SecurityMiddleware] FIRMA HMAC INVÁLIDA");
            return false;
        }

        return true;
    }

    /**
     * Valida fingerprint del dispositivo y detecta anomalías.
     */
    public static function validateDeviceFingerprint(): array
    {
        $fingerprint = $_SERVER['HTTP_X_DEVICE_FINGERPRINT'] ?? null;
        $integrityScore = intval($_SERVER['HTTP_X_INTEGRITY_SCORE'] ?? 0);
        $integrityWarning = $_SERVER['HTTP_X_INTEGRITY_WARNING'] ?? null;

        $result = [
            'valid' => true,
            'fingerprint' => $fingerprint ? substr($fingerprint, 0, 12) . '...' : null, // Truncado en logs
            'risk_score' => $integrityScore,
            'alerts' => [],
        ];

        if (!$fingerprint || strlen($fingerprint) < 32) {
            $result['valid'] = false;
            $result['alerts'][] = 'MISSING_FINGERPRINT';
        }

        if ($integrityWarning === 'HIGH_RISK') {
            $result['alerts'][] = 'HIGH_RISK_DEVICE';
        }

        // Detección multi-cuenta
        if ($fingerprint) {
            $accountCount = self::countAccountsForDevice($fingerprint);
            if ($accountCount > 3) {
                $result['alerts'][] = 'MULTI_ACCOUNT_DEVICE';
                $result['risk_score'] += 20;
                error_log("[SecurityMiddleware] MULTI-CUENTA: {$accountCount} cuentas en dispositivo");
            }
        }

        return $result;
    }

    /**
     * Rate limiting por IP y dispositivo.
     */
    public static function checkRateLimit(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $fingerprint = $_SERVER['HTTP_X_DEVICE_FINGERPRINT'] ?? $ip;

        try {
            if (!class_exists('Cache')) return true;
            $redis = Cache::redis();
            if (!$redis) return true;

            // Rate limit por IP
            $ipKey = "rate_limit:ip:{$ip}";
            $ipCount = $redis->incr($ipKey);
            if ($ipCount === 1) {
                $redis->expire($ipKey, self::RATE_WINDOW_SECONDS);
            }
            if ($ipCount > self::RATE_LIMIT_PER_IP) {
                error_log("[SecurityMiddleware] RATE LIMIT IP: {$ip} ({$ipCount} req)");
                return false;
            }

            // Rate limit por dispositivo
            $deviceKey = "rate_limit:device:" . substr($fingerprint, 0, 16);
            $deviceCount = $redis->incr($deviceKey);
            if ($deviceCount === 1) {
                $redis->expire($deviceKey, self::RATE_WINDOW_SECONDS);
            }
            if ($deviceCount > self::RATE_LIMIT_PER_DEVICE) {
                error_log("[SecurityMiddleware] RATE LIMIT DEVICE");
                return false;
            }
        } catch (Throwable $e) {
            return true; // Fail-safe
        }

        return true;
    }

    /**
     * Validación completa con Soft Enforcement.
     * En modo "monitor": solo loggea, nunca bloquea.
     * En modo "partial": bloquea solo requests sin session_key de usuarios nuevos.
     * En modo "enforce": bloqueo total.
     */
    public static function fullSecurityCheck(string $requestPath, string $requestBody, int $userId = 0): ?array
    {
        $mode = self::getEnforcementMode();
        $violations = [];

        // 1. Rate Limiting (siempre activo, incluso en monitor)
        if (!self::checkRateLimit()) {
            if ($mode === 'enforce') {
                http_response_code(429);
                return ['error' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Demasiadas solicitudes.'];
            }
            $violations[] = 'RATE_LIMIT';
        }

        $deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
        if (empty($deviceId)) {
            // Extraer del fingerprint si es posible o usar un default para logs
            $deviceId = substr($_SERVER['HTTP_X_DEVICE_FINGERPRINT'] ?? 'unknown', 0, 16);
        }

        // 2. Validar session_key
        $sessionKey = null;
        if ($userId > 0 && !empty($deviceId)) {
            $sessionKey = self::validateSessionKey($userId, $deviceId);
        }

        // 3. Validar firma HMAC (con excepciones)
        if (!self::isExemptFromHMAC($requestPath)) {
            $hmacValid = self::validateHmacSignature($requestPath, $requestBody, $userId, $deviceId, $sessionKey);
            if (!$hmacValid) {
                $violations[] = 'HMAC_INVALID';
            }
        }

        // 4. Validar fingerprint
        $deviceCheck = self::validateDeviceFingerprint();
        if (!$deviceCheck['valid']) {
            $violations[] = 'FINGERPRINT_INVALID';
        }
        if ($deviceCheck['risk_score'] >= 60) {
            $violations[] = 'HIGH_RISK_SCORE';
        }

        // Decisión según modo de enforcement
        if (!empty($violations)) {
            // Log seguro de las violaciones (sin datos sensibles)
            error_log("[SecurityMiddleware] [{$mode}] Violaciones: " . implode(', ', $violations) . " | path={$requestPath} | user={$userId} | ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

            // Registrar evento de seguridad
            self::logSecurityEvent('SECURITY_VIOLATIONS', [
                'violations' => $violations,
                'path' => $requestPath,
                'user_id' => $userId,
                'mode' => $mode,
                'risk_score' => $deviceCheck['risk_score'],
            ]);

            if ($mode === 'enforce') {
                http_response_code(403);
                return ['error' => 'SECURITY_VIOLATION', 'message' => 'Solicitud rechazada por política de seguridad.'];
            }

            if ($mode === 'partial' && in_array('HMAC_INVALID', $violations) && $sessionKey === null) {
                // En parcial: si no tiene session_key Y HMAC falla, let it pass
                // Solo bloquear si es claramente un intento de bypass
                if (in_array('HIGH_RISK_SCORE', $violations)) {
                    http_response_code(403);
                    return ['error' => 'SECURITY_VIOLATION', 'message' => 'Dispositivo de alto riesgo detectado.'];
                }
            }

            // En modo "monitor": solo logs, no bloquear
        }

        return null; // Todo OK (o modo monitor/parcial sin bloqueo)
    }

    /**
     * Endpoints exentos de verificación HMAC
     */
    private static function isExemptFromHMAC(string $path): bool
    {
        $exemptPaths = ['/auth/', '/legal/', '/health.php', 'current_version', 'session_key'];
        foreach ($exemptPaths as $exempt) {
            if (strpos($path, $exempt) !== false) return true;
        }
        return false;
    }

    private static function isNonceUsed(string $nonce): bool
    {
        try {
            if (!class_exists('Cache')) return false;
            $redis = Cache::redis();
            if (!$redis) return false;
            return $redis->exists("nonce:{$nonce}") > 0;
        } catch (Throwable $e) { return false; }
    }

    private static function markNonceUsed(string $nonce): void
    {
        try {
            if (!class_exists('Cache')) return;
            $redis = Cache::redis();
            if (!$redis) return;
            $redis->setex("nonce:{$nonce}", 600, '1');
        } catch (Throwable $e) {}
    }

    private static function countAccountsForDevice(string $fingerprint): int
    {
        try {
            $database = new Database();
            $db = $database->getConnection();
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT user_id) as count 
                FROM legal_acceptance_logs 
                WHERE device_id = ? AND accepted_at > NOW() - INTERVAL '30 days'
            ");
            $stmt->execute([$fingerprint]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? intval($row['count']) : 0;
        } catch (Throwable $e) { return 0; }
    }

    private static function logSecurityEvent(string $eventType, array $data): void
    {
        try {
            $database = new Database();
            $db = $database->getConnection();
            $stmt = $db->prepare("
                INSERT INTO security_events (event_type, event_data, ip_address, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([
                $eventType,
                json_encode($data),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        } catch (Throwable $e) {
            // Solo loggear si la tabla aún no existe
            error_log("[SecurityMiddleware] EVENT [{$eventType}]: " . json_encode($data));
        }
    }
}
