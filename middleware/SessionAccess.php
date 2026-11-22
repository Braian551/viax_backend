<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Auth.php';

final class SessionAccess
{
    private const COOKIE = '__Host-viax-session';
    private const IDLE_SECONDS = 3600;
    private const MAX_SECONDS = 1209600;

    public static function requireBrowserOrigin(): void
    {
        // SameSite is supplementary. Every cookie-authenticated write checks its origin.
        $origin = Request::header('Origin');
        if ($origin === null || !in_array($origin, viax_allowed_origins(), true)) {
            sendJsonResponse(false, 'Origen no permitido.', [], 403, 'FORBIDDEN_ORIGIN');
        }
    }

    public static function createWebSession(int $userId): bool
    {
        $token = Auth::generateAccessToken();
        $now = time();
        $payload = json_encode([
            'user_id' => $userId,
            'web_only' => true,
            'issued_at' => $now,
            'expires_at' => $now + self::IDLE_SECONDS,
        ]);
        if ($payload === false || !Cache::set('user_session:' . $token, $payload, self::IDLE_SECONDS)) {
            return false;
        }

        self::revokeCookieToken();
        self::setCookie($token, $now + self::IDLE_SECONDS);
        return true;
    }

    public static function renewWebSession(): bool
    {
        $token = self::cookieToken();
        $redis = Cache::redis();
        if ($token === null || $redis === null) {
            return false;
        }

        // Extend the existing key atomically. A concurrent logout cannot recreate it.
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local session = cjson.decode(raw)
local now = tonumber(ARGV[1])
if session.web_only ~= true or session.revoked or session.expires_at <= now then return 0 end
local expires = math.min(session.issued_at + tonumber(ARGV[3]), now + tonumber(ARGV[2]))
if expires <= now then return 0 end
session.expires_at = expires
redis.call('SET', KEYS[1], cjson.encode(session), 'EX', expires - now)
return expires
LUA;
        $expires = (int)$redis->eval($script, [
            'user_session:' . $token, time(), self::IDLE_SECONDS, self::MAX_SECONDS,
        ], 1);
        if ($expires === 0) {
            return false;
        }

        self::setCookie($token, $expires);
        return true;
    }

    public static function revokeWebSession(): void
    {
        self::revokeCookieToken();
        self::setCookie('', time() - 3600);
    }

    private static function revokeCookieToken(): void
    {
        $token = self::cookieToken();
        if ($token !== null) {
            $key = 'user_session:' . $token;
            $redis = Cache::redis();
            if ($redis === null) {
                throw new RuntimeException('Session storage unavailable');
            }
            $redis->eval(<<<'LUA'
local raw = redis.call('GET', KEYS[1])
if raw and cjson.decode(raw).web_only == true then
    redis.call('DEL', KEYS[1])
end
return 1
LUA, [$key], 1);
        }
    }

    public static function requireUser(PDO $db, bool $webOnly = false): array
    {
        header('Cache-Control: no-store');
        $authorization = Request::header('Authorization');
        $fromCookie = $webOnly || $authorization === null;
        $token = $fromCookie ? self::cookieToken() : Auth::bearerToken();
        $session = $token === null ? null : Auth::getSessionFromCache($token);
        if (!$session || empty($session['user_id']) || !empty($session['web_only']) !== $fromCookie) {
            sendJsonResponse(false, 'Tu sesión venció. Inicia sesión para continuar.', [], 401, 'SESSION_EXPIRED');
        }
        if ($fromCookie && Request::method() !== 'GET') {
            self::requireBrowserOrigin();
        }

        $stmt = $db->prepare(
            "SELECT id, uuid, nombre, apellido, email, telefono, tipo_usuario, empresa_id
             FROM usuarios WHERE id = :id AND status = 'active'"
        );
        $stmt->execute([':id' => (int)$session['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            sendJsonResponse(false, 'Tu sesión venció. Inicia sesión para continuar.', [], 401, 'SESSION_EXPIRED');
        }
        return $user;
    }

    public static function requireAdmin(PDO $db): array
    {
        $user = self::requireUser($db);
        if (!in_array($user['tipo_usuario'], ['administrador', 'admin'], true)) {
            sendJsonResponse(false, 'Acceso denegado.', [], 403, 'FORBIDDEN');
        }
        return $user;
    }

    public static function requireOwner(array $input, int $userId): void
    {
        if (array_key_exists('usuario_id', $input)
            && filter_var($input['usuario_id'], FILTER_VALIDATE_INT) !== $userId) {
            sendJsonResponse(false, 'Acceso denegado.', [], 403, 'FORBIDDEN');
        }
    }

    private static function cookieToken(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        return is_string($token) && Auth::isPlausibleToken($token) ? $token : null;
    }

    private static function setCookie(string $token, int $expires): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => $expires, 'path' => '/', 'secure' => true,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
}
