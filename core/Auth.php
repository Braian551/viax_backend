<?php
/**
 * Utilidades de autenticacion reutilizables.
 *
 * Mantiene compatibilidad con el esquema actual `user_session:{token}`
 * y agrega soporte incremental para:
 * - expiracion de access token
 * - refresh token
 * - invalidacion en logout
 */
class Auth
{
    public static function bearerToken(): ?string
    {
        $header = Request::header('Authorization');
        if (!$header || stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));
        return self::isPlausibleToken($token) ? $token : null;
    }

    public static function isPlausibleToken(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        if (strlen($token) < 16 || strlen($token) > 512) {
            return false;
        }
        return (bool)preg_match('/^[A-Za-z0-9\-\._~\+\/]+=*$/', $token);
    }

    public static function getSessionFromCache(string $token): ?array
    {
        if (!self::isPlausibleToken($token) || !class_exists('Cache')) {
            return null;
        }

        $raw = Cache::get('user_session:' . $token);
        if (!$raw) {
            return null;
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        // Compatibilidad: sesiones legacy sin expires_at siguen vigentes por TTL de Redis.
        $expiresAt = isset($decoded['expires_at']) ? (int)$decoded['expires_at'] : 0;
        if ($expiresAt > 0 && $expiresAt <= time()) {
            Cache::delete('user_session:' . $token);
            return null;
        }

        if (!empty($decoded['revoked'])) {
            Cache::delete('user_session:' . $token);
            return null;
        }

        return $decoded;
    }

    public static function putSessionInCache(string $token, array $session, int $ttlSeconds = 3600): bool
    {
        if (!self::isPlausibleToken($token) || !class_exists('Cache')) {
            return false;
        }

        $now = time();
        $accessTtl = max(60, $ttlSeconds > 0 ? $ttlSeconds : (int)env_value('AUTH_ACCESS_TOKEN_TTL', 3600));
        $refreshTtl = max($accessTtl + 60, (int)env_value('AUTH_REFRESH_TOKEN_TTL', 1209600)); // 14 dias

        if (!isset($session['issued_at'])) {
            $session['issued_at'] = $now;
        }
        if (!isset($session['expires_at'])) {
            $session['expires_at'] = $now + $accessTtl;
        }
        if (empty($session['refresh_token']) || !is_string($session['refresh_token'])) {
            $session['refresh_token'] = self::generateRefreshToken();
        }
        $session['access_token'] = $token;
        $session['revoked'] = false;
        $session['session_version'] = isset($session['session_version'])
            ? (int)$session['session_version']
            : 1;

        $payload = json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return false;
        }

        $saved = Cache::set('user_session:' . $token, $payload, $accessTtl);
        if (!$saved) {
            return false;
        }

        $refreshPayload = [
            'user_id' => isset($session['user_id']) ? (int)$session['user_id'] : null,
            'device_uuid' => $session['device_uuid'] ?? null,
            'email' => $session['email'] ?? null,
            'issued_at' => $now,
            'expires_at' => $now + $refreshTtl,
            'access_token' => $token,
            'session_version' => (int)$session['session_version'],
            'revoked' => false,
        ];

        Cache::set(
            'user_refresh_session:' . $session['refresh_token'],
            (string)json_encode($refreshPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $refreshTtl
        );

        return true;
    }

    public static function refreshSession(string $refreshToken): ?array
    {
        if (!self::isPlausibleToken($refreshToken) || !class_exists('Cache')) {
            return null;
        }

        $raw = Cache::get('user_refresh_session:' . $refreshToken);
        if (!$raw) {
            return null;
        }

        $refreshData = json_decode((string)$raw, true);
        if (!is_array($refreshData)) {
            return null;
        }

        $expiresAt = isset($refreshData['expires_at']) ? (int)$refreshData['expires_at'] : 0;
        if ($expiresAt > 0 && $expiresAt <= time()) {
            Cache::delete('user_refresh_session:' . $refreshToken);
            return null;
        }
        if (!empty($refreshData['revoked'])) {
            Cache::delete('user_refresh_session:' . $refreshToken);
            return null;
        }

        $oldAccessToken = isset($refreshData['access_token']) ? (string)$refreshData['access_token'] : '';
        $oldSession = $oldAccessToken !== '' ? self::getSessionFromCache($oldAccessToken) : null;
        if (!is_array($oldSession)) {
            return null;
        }

        $newAccessToken = self::generateAccessToken();
        $newRefreshToken = self::generateRefreshToken();

        // Rotacion de refresh token para reducir replay.
        self::revokeRefreshToken($refreshToken);
        self::revokeSession($oldAccessToken, 'rotated');

        $sessionData = $oldSession;
        $sessionData['refresh_token'] = $newRefreshToken;
        $sessionData['session_version'] = ((int)($oldSession['session_version'] ?? 1)) + 1;
        $sessionData['rotated_at'] = time();

        $saved = self::putSessionInCache($newAccessToken, $sessionData, (int)env_value('AUTH_ACCESS_TOKEN_TTL', 3600));
        if (!$saved) {
            return null;
        }

        return [
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in' => (int)env_value('AUTH_ACCESS_TOKEN_TTL', 3600),
            'user_id' => (int)($sessionData['user_id'] ?? 0),
        ];
    }

    public static function revokeSession(string $token, string $reason = 'logout'): bool
    {
        if (!self::isPlausibleToken($token) || !class_exists('Cache')) {
            return false;
        }

        $session = self::getSessionFromCache($token);
        if (is_array($session)) {
            $session['revoked'] = true;
            $session['revoked_at'] = time();
            $session['revoked_reason'] = $reason;
            Cache::set('user_session:' . $token, (string)json_encode($session), 60);

            $refreshToken = isset($session['refresh_token']) ? (string)$session['refresh_token'] : '';
            if ($refreshToken !== '') {
                self::revokeRefreshToken($refreshToken);
            }
        }

        Cache::delete('user_session:' . $token);
        return true;
    }

    public static function revokeRefreshToken(string $refreshToken): bool
    {
        if (!self::isPlausibleToken($refreshToken) || !class_exists('Cache')) {
            return false;
        }

        $raw = Cache::get('user_refresh_session:' . $refreshToken);
        if ($raw) {
            $payload = json_decode((string)$raw, true);
            if (is_array($payload)) {
                $payload['revoked'] = true;
                $payload['revoked_at'] = time();
                Cache::set('user_refresh_session:' . $refreshToken, (string)json_encode($payload), 60);
            }
        }

        Cache::delete('user_refresh_session:' . $refreshToken);
        return true;
    }

    public static function generateAccessToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function generateRefreshToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }
}
