<?php
/**
 * Guard para bypass de codigos de prueba (ej: 8052).
 *
 * Reglas:
 * - Activo siempre fuera de produccion.
 * - En produccion, solo con TEST_BYPASS_ENABLED=true.
 * - Rate limit estricto para evitar abuso.
 * - Restriccion opcional por IP.
 */
declare(strict_types=1);

require_once __DIR__ . '/Feature.php';
require_once __DIR__ . '/RateLimiter.php';

class TestBypass
{
    private const QA_CODE = '8052';

    public static function shouldAllowCode(string $code, string $scope = 'auth', ?string $identity = null): bool
    {
        if (trim($code) !== self::QA_CODE) {
            return false;
        }

        if (!self::isBypassEnabled()) {
            return false;
        }

        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!self::isAllowedIp($ip)) {
            error_log('[security][test_bypass] blocked_by_ip scope=' . $scope . ' ip=' . self::maskIp($ip));
            return false;
        }

        $normalizedIdentity = trim((string)$identity);
        if ($normalizedIdentity === '') {
            $normalizedIdentity = $ip !== '' ? $ip : 'unknown';
        }
        $identityHash = substr(hash('sha256', $normalizedIdentity), 0, 16);

        $maxRequests = max(1, (int)env_value('TEST_BYPASS_RATE_LIMIT_MAX', 5));
        $windowSeconds = max(10, (int)env_value('TEST_BYPASS_RATE_LIMIT_WINDOW_SEC', 300));
        $scopeKey = $scope . ':' . $identityHash;

        if (!RateLimiter::check($scopeKey, $maxRequests, $windowSeconds, 'test_bypass_8052')) {
            error_log('[security][test_bypass] rate_limited scope=' . $scope . ' identity=' . $identityHash);
            return false;
        }

        $audit = [
            'scope' => $scope,
            'identity' => $identityHash,
            'ip' => self::maskIp($ip),
        ];
        error_log('[security][test_bypass] used ' . json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return true;
    }

    private static function isBypassEnabled(): bool
    {
        $appEnv = strtolower(trim((string)env_value('APP_ENV', 'production')));
        if ($appEnv !== 'production') {
            return true;
        }

        return Feature::isEnabled('test_bypass', false);
    }

    private static function isAllowedIp(string $ip): bool
    {
        $raw = trim((string)env_value('TEST_BYPASS_ALLOWED_IPS', ''));
        if ($raw === '') {
            return true;
        }

        if ($ip === '') {
            return false;
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $raw))));
        foreach ($allowed as $candidate) {
            if ($candidate === $ip) {
                return true;
            }
        }

        return false;
    }

    private static function maskIp(string $ip): string
    {
        if ($ip === '') {
            return 'unknown';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = 'x';
                return implode('.', $parts);
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return substr($ip, 0, 8) . '::';
        }

        return 'unknown';
    }
}
