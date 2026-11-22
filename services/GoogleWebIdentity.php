<?php

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

require_once __DIR__ . '/../middleware/SessionAccess.php';

final class GoogleWebIdentity
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const KEY_CACHE = 'auth:google:web:jwks';

    public static function verify(string $credential, string $clientId, bool $requireNonce = true, ?string $nativeClientId = null): array
    {
        return self::verifyWithKeys($credential, $clientId, self::googleKeys(), $requireNonce, $nativeClientId);
    }

    public static function verifyWithKeys(string $credential, string $clientId, array $keys, bool $requireNonce = true, ?string $nativeClientId = null): array
    {
        $signingKeys = array_filter($keys['keys'] ?? [], static fn ($key) =>
            ($key['kty'] ?? null) === 'RSA' && ($key['alg'] ?? null) === 'RS256'
            && ($key['use'] ?? null) === 'sig'
        );
        $claims = (array)JWT::decode($credential, JWK::parseKeySet(['keys' => $signingKeys], 'RS256'));
        if (!in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            || ($claims['aud'] ?? null) !== $clientId
            || (isset($claims['azp']) && !in_array($claims['azp'], array_filter([$clientId, $nativeClientId]), true))
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
            || !is_string($claims['sub'] ?? null) || !preg_match('/^[0-9]{1,64}$/D', $claims['sub'])
            || ($claims['email_verified'] ?? null) !== true
            || !is_string($claims['email'] ?? null) || strlen($claims['email']) > 254
            || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
            || ($requireNonce && !is_string($claims['nonce'] ?? null))) {
            throw new UnexpectedValueException('Invalid Google claims');
        }

        return $claims;
    }

    private static function googleKeys(): array
    {
        $raw = Cache::get(self::KEY_CACHE);
        if (is_string($raw)) {
            $cached = json_decode($raw, true);
            if (is_array($cached) && !empty($cached['keys'])) {
                return $cached;
            }
        }

        $maxAge = 0;
        $request = curl_init(self::JWKS_URL);
        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$maxAge): int {
                if (stripos($line, 'Cache-Control:') === 0 && preg_match('/max-age=(\d+)/i', $line, $match)) {
                    $maxAge = (int)$match[1];
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($request);
        $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);
        $jwks = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($jwks) || empty($jwks['keys'])) {
            throw new RuntimeException('Google verification keys unavailable');
        }
        if ($maxAge > 0) {
            Cache::set(self::KEY_CACHE, $body, min($maxAge, 3600));
        }
        return $jwks;
    }
}
