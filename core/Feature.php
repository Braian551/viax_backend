<?php
/**
 * Feature flags centralizadas.
 *
 * Uso:
 *   Feature::enabled('upfront_pricing');
 *   Feature::enabled('use_new_services');
 */
class Feature
{
    /** @var array<string,bool> */
    private static array $cache = [];

    public static function isEnabled(string $flag, bool $default = false): bool
    {
        return self::enabled($flag, $default);
    }

    public static function enabled(string $flag, bool $default = false): bool
    {
        $normalized = strtolower(trim($flag));
        if ($normalized === '') {
            return $default;
        }

        if (array_key_exists($normalized, self::$cache)) {
            return self::$cache[$normalized];
        }

        $envKey = self::envKeyFor($normalized);
        $raw = env_value($envKey, $default ? '1' : '0');
        $enabled = self::toBool($raw);

        self::$cache[$normalized] = $enabled;
        return $enabled;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function envKeyFor(string $flag): string
    {
        $map = [
            'upfront_pricing' => 'UPFRONT_PRICING_ENABLED',
            'use_new_services' => 'USE_NEW_SERVICES',
            'strict_cors_block' => 'CORS_STRICT_MODE',
            'test_bypass' => 'TEST_BYPASS_ENABLED',
            'upfront_pricing_margin_protection' => 'UPFRONT_PRICING_MARGIN_PROTECTION',
            'upfront_pricing_margin_protection_ratio' => 'UPFRONT_PRICING_MAX_LOSS_RATIO',
            'driver_fraud_detection' => 'DRIVER_FRAUD_DETECTION_ENABLED',
        ];

        if (isset($map[$flag])) {
            return $map[$flag];
        }

        return strtoupper(str_replace(['.', '-'], '_', $flag));
    }

    private static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on', 't', 'si', 's'], true);
    }
}
