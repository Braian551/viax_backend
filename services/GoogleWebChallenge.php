<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';

final class GoogleWebChallenge
{
    private const COOKIE = '__Host-viax-google';
    private const TTL = 600;

    public static function issue(): ?string
    {
        $nonce = bin2hex(random_bytes(32));
        $browserKey = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($browserKey) || !preg_match('/^[a-f0-9]{64}$/D', $browserKey)) {
            $browserKey = bin2hex(random_bytes(32));
        }
        if (!Cache::set(self::cacheKey($nonce), hash('sha256', $browserKey), self::TTL)) {
            return null;
        }
        setcookie(self::COOKIE, $browserKey, [
            'expires' => time() + self::TTL, 'path' => '/', 'secure' => true,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        return $nonce;
    }

    public static function consume(string $nonce): bool
    {
        $browserKey = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($browserKey) || !preg_match('/^[a-f0-9]{64}$/D', $browserKey)
            || !preg_match('/^[a-f0-9]{64}$/D', $nonce)) {
            return false;
        }
        $redis = Cache::redis();
        if (!$redis) {
            throw new RuntimeException('Google challenge storage unavailable');
        }

        return (int)$redis->eval(<<<'LUA'
local saved = redis.call('GET', KEYS[1])
if not saved or saved ~= ARGV[1] then return 0 end
redis.call('DEL', KEYS[1])
return 1
LUA, [self::cacheKey($nonce), hash('sha256', $browserKey)], 1) === 1;
    }

    private static function cacheKey(string $nonce): string
    {
        return 'auth:google:web:challenge:' . $nonce;
    }
}
