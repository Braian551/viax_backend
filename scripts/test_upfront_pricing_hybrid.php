<?php
/**
 * Pruebas CLI de hardening + pricing hibrido.
 *
 * Uso:
 *   php backend/scripts/test_upfront_pricing_hybrid.php
 */

if (!function_exists('env_value')) {
    function env_value(string $key, $default = null) {
        $value = getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }
}

require_once __DIR__ . '/../services/upfront_pricing_service.php';
require_once __DIR__ . '/../config/timezone.php';
require_once __DIR__ . '/../core/TestBypass.php';

function assertEqualsFloat(string $label, float $actual, float $expected, float $epsilon = 0.01): void
{
    if (abs($actual - $expected) > $epsilon) {
        throw new RuntimeException($label . ' esperado=' . $expected . ' actual=' . $actual);
    }
}

function assertTrue(string $label, bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException($label . ' esperado=true actual=false');
    }
}

function assertFalse(string $label, bool $condition): void
{
    if ($condition) {
        throw new RuntimeException($label . ' esperado=false actual=true');
    }
}

function isInefficientRoute(float $distanciaOptima, float $distanciaReal): bool
{
    $distanciaOptima = max(0.001, $distanciaOptima);
    $distanciaReal = max(0.001, $distanciaReal);
    $eficiencia = $distanciaOptima / $distanciaReal;
    return $eficiencia < 0.7;
}

function isNightPricingHour(DateTimeInterface $dateTime): bool
{
    $hour = (int)$dateTime->setTimezone(new DateTimeZone('America/Bogota'))->format('H');
    return $hour >= 22 || $hour < 5;
}

$tests = [];

$tests[] = [
    'name' => '1) viaje normal',
    'run' => static function (): void {
        $result = UpfrontPricingService::resolveFinalCharge(
            12000,
            12000,
            true,
            true,
            false,
            false,
            false
        );

        assertEqualsFloat('precio_final', (float)$result['precio_final'], 12000);
        assertTrue('precio_congelado', (bool)$result['precio_congelado']);
        assertFalse('recalculo_forzado', (bool)$result['recalculo_forzado']);
    },
];

$tests[] = [
    'name' => '2) usuario no se mueve (tracking invalido)',
    'run' => static function (): void {
        $result = UpfrontPricingService::resolveFinalCharge(
            19000,
            12000,
            true,
            true,
            false,
            false,
            false,
            false // tracking invalido: distancia/tiempo insuficientes
        );

        assertFalse('recalculo_forzado_tracking_invalido', (bool)$result['recalculo_forzado']);
        assertTrue('precio_congelado_tracking_invalido', (bool)$result['precio_congelado']);
        assertEqualsFloat('precio_final_tracking_invalido', (float)$result['precio_final'], 12000);
    },
];

$tests[] = [
    'name' => '3) desviacion alta (>30%)',
    'run' => static function (): void {
        $result = UpfrontPricingService::resolveFinalCharge(
            17000,
            12000,
            true,
            true,
            false,
            false,
            false
        );

        assertTrue('recalculo_forzado', (bool)$result['recalculo_forzado']);
        assertFalse('precio_congelado', (bool)$result['precio_congelado']);
        assertEqualsFloat('precio_final', (float)$result['precio_final'], 17000);
    },
];

$tests[] = [
    'name' => '4) tarifa nocturna hora Colombia',
    'run' => static function (): void {
        $night = new DateTime('2026-03-30 23:15:00', new DateTimeZone('America/Bogota'));
        $day = new DateTime('2026-03-30 14:15:00', new DateTimeZone('America/Bogota'));

        assertTrue('night_window_detected', isNightPricingHour($night));
        assertFalse('day_window_not_night', isNightPricingHour($day));
    },
];

$tests[] = [
    'name' => '5) cambio destino',
    'run' => static function (): void {
        $result = UpfrontPricingService::resolveFinalCharge(
            13000,
            12000,
            true,
            true,
            true,   // trigger cambio destino
            false,
            false
        );

        assertTrue('recalculo_forzado', (bool)$result['recalculo_forzado']);
        assertEqualsFloat('precio_final', (float)$result['precio_final'], 13000);
    },
];

$tests[] = [
    'name' => '6) fraude ruta ineficiente',
    'run' => static function (): void {
        assertTrue('inefficient_route_detected', isInefficientRoute(8.0, 13.0));
        assertFalse('route_ok_not_flagged', isInefficientRoute(8.0, 9.5));
    },
];

$tests[] = [
    'name' => '7) uso de codigo 8052 solo con flag activo',
    'run' => static function (): void {
        putenv('APP_ENV=production');
        putenv('TEST_BYPASS_ENABLED=1');
        putenv('TEST_BYPASS_RATE_LIMIT_MAX=10');
        putenv('TEST_BYPASS_RATE_LIMIT_WINDOW_SEC=60');
        putenv('TEST_BYPASS_ALLOWED_IPS=');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Feature::clearCache();

        $allowed = TestBypass::shouldAllowCode('8052', 'test_script', 'script-user@example.com');
        assertTrue('bypass_enabled_with_flag', $allowed);

        putenv('TEST_BYPASS_ENABLED=0');
        Feature::clearCache();
        $blocked = TestBypass::shouldAllowCode('8052', 'test_script', 'script-user@example.com');
        assertFalse('bypass_disabled_without_flag', $blocked);
    },
];

echo "============================================\n";
echo " TESTS HARDENING + PRICING HIBRIDO (VIAX) \n";
echo "============================================\n\n";

$passed = 0;
$failed = 0;

foreach ($tests as $case) {
    $name = (string)$case['name'];
    try {
        $runner = $case['run'];
        $runner();
        $passed++;
        echo "[OK] " . $name . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "[FAIL] " . $name . " -> " . $e->getMessage() . "\n";
    }
}

echo "\nResumen: OK=" . $passed . " FAIL=" . $failed . "\n";
exit($failed > 0 ? 1 : 0);
