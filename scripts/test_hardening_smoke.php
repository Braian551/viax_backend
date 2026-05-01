<?php
/**
 * Smoke tests de hardening (rapidos, sin tocar DB).
 *
 * Ejecutar:
 *   php scripts/test_hardening_smoke.php
 */

if (!function_exists('env_value')) {
    function env_value(string $key, $default = null) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }
}

require_once __DIR__ . '/../core/Feature.php';
require_once __DIR__ . '/../services/upfront_pricing_service.php';
require_once __DIR__ . '/../core/Auth.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "OK: {$message}\n";
}

// Fase 3 - Proteccion de margen.
$_ENV['UPFRONT_PRICING_MARGIN_PROTECTION'] = '1';
$_ENV['UPFRONT_PRICING_MAX_LOSS_RATIO'] = '0.2';
$decisionMargin = UpfrontPricingService::resolveFinalCharge(
    15000.0,
    10000.0,
    true,
    true,
    false,
    false,
    false
);
assertTrue((bool)$decisionMargin['recalculo_forzado'] === true, 'Margin protection fuerza recalculo');
assertTrue((bool)$decisionMargin['precio_congelado'] === false, 'Margin protection descongela precio');
assertTrue(in_array('margin_protection', $decisionMargin['reasons'], true), 'Reason margin_protection presente');

// Flujo normal sin desviaciones fuertes.
$decisionNormal = UpfrontPricingService::resolveFinalCharge(
    10100.0,
    10000.0,
    true,
    true,
    false,
    false,
    false
);
assertTrue((bool)$decisionNormal['recalculo_forzado'] === false, 'Sin triggers mantiene precio fijo');
assertTrue((float)$decisionNormal['precio_final'] === 10000.0, 'Precio final fijo en viaje normal');

// Fase 5/10 - Feature flags y rate-limit API presentes.
assertTrue(Feature::enabled('upfront_pricing_margin_protection', true) === true, 'Feature flags centralizado responde');

// Fase 11 - Auth token hardening (generacion/validacion basica).
$access = Auth::generateAccessToken();
$refresh = Auth::generateRefreshToken();
assertTrue(Auth::isPlausibleToken($access), 'Access token generado es valido');
assertTrue(Auth::isPlausibleToken($refresh), 'Refresh token generado es valido');

echo "HARDENING_SMOKE_OK\n";
