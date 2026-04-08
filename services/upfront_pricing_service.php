<?php
/**
 * Reglas de pricing híbrido (upfront + cálculo real).
 *
 * Objetivo:
 * - Cobrar al usuario precio fijo cuando corresponde.
 * - Mantener cálculo real para métricas, pagos al conductor y margen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/Feature.php';

class UpfrontPricingService
{
    /**
     * Feature flag global.
     */
    public static function isEnabled(): bool
    {
        return Feature::enabled('upfront_pricing');
    }

    /**
     * Decide el precio final a cobrar al usuario.
     *
     * @return array{
     *   precio_real: float,
     *   precio_fijo: float,
     *   precio_final: float,
     *   precio_congelado: bool,
     *   desviacion_ratio: float,
     *   desviacion_porcentaje: float,
     *   tracking_valido: bool,
     *   recalculo_forzado: bool,
     *   reasons: array<int, string>
     * }
     */
    public static function resolveFinalCharge(
        float $precioReal,
        ?float $precioFijo,
        bool $precioCongeladoActual,
        bool $upfrontEnabled,
        bool $triggerDestinoCambio,
        bool $triggerDesvioFuerte,
        bool $triggerParadasAdicionales,
        bool $trackingValido = true,
        float $desviacionMaximaRatio = 0.30
    ): array {
        $precioReal = max(0.0, $precioReal);
        $precioFijoNormalizado = max(0.0, (float)($precioFijo ?? 0.0));
        $trackingValido = (bool)$trackingValido;

        if (!$upfrontEnabled || !$precioCongeladoActual || $precioFijoNormalizado <= 0) {
            return [
                'precio_real' => round($precioReal, 2),
                'precio_fijo' => round($precioFijoNormalizado, 2),
                'precio_final' => round($precioReal, 2),
                'precio_congelado' => false,
                'desviacion_ratio' => 0.0,
                'desviacion_porcentaje' => 0.0,
                'tracking_valido' => $trackingValido,
                'recalculo_forzado' => false,
                'reasons' => ['fallback_legacy_flow'],
            ];
        }

        $desviacionRatio = abs($precioReal - $precioFijoNormalizado) / $precioFijoNormalizado;
        $desviacionPorcentaje = $desviacionRatio * 100.0;

        $reasons = [];
        if ($trackingValido && $desviacionRatio > $desviacionMaximaRatio) {
            $reasons[] = 'high_price_deviation';
        }
        $marginProtectionEnabled = Feature::enabled('upfront_pricing_margin_protection', true);
        $maxLossRatio = (float)env_value('UPFRONT_PRICING_MAX_LOSS_RATIO', '0.20');
        if ($maxLossRatio <= 0) {
            $maxLossRatio = 0.20;
        }
        if (
            $trackingValido &&
            $marginProtectionEnabled &&
            $precioReal > ($precioFijoNormalizado * (1.0 + $maxLossRatio))
        ) {
            $reasons[] = 'margin_protection';
        }
        if ($triggerDestinoCambio) {
            $reasons[] = 'destination_changed';
        }
        if ($triggerDesvioFuerte) {
            $reasons[] = 'strong_route_deviation';
        }
        if ($triggerParadasAdicionales) {
            $reasons[] = 'additional_stops';
        }

        $recalculoForzado = !empty($reasons);
        $precioFinal = $recalculoForzado ? $precioReal : $precioFijoNormalizado;
        $precioCongeladoFinal = !$recalculoForzado;

        return [
            'precio_real' => round($precioReal, 2),
            'precio_fijo' => round($precioFijoNormalizado, 2),
            'precio_final' => round($precioFinal, 2),
            'precio_congelado' => $precioCongeladoFinal,
            'desviacion_ratio' => round($desviacionRatio, 6),
            'desviacion_porcentaje' => round($desviacionPorcentaje, 2),
            'tracking_valido' => $trackingValido,
            'recalculo_forzado' => $recalculoForzado,
            'reasons' => $reasons,
        ];
    }

    /**
     * Pago al conductor basado en recorrido real.
     *
     * @return array{
     *   costo_real: float,
     *   comision_porcentaje: float,
     *   comision_valor: float,
     *   pago_conductor: float
     * }
     */
    public static function calculateDriverCompensation(
        float $distanciaRealKm,
        int $tiempoRealMin,
        float $tarifaPorKm,
        float $tarifaPorMin,
        float $comisionPorcentaje
    ): array {
        $distancia = max(0.0, $distanciaRealKm);
        $tiempo = max(0, $tiempoRealMin);
        $tarifaKm = max(0.0, $tarifaPorKm);
        $tarifaMin = max(0.0, $tarifaPorMin);
        $comision = min(100.0, max(0.0, $comisionPorcentaje));

        $costoReal = ($distancia * $tarifaKm) + ($tiempo * $tarifaMin);
        $comisionValor = $costoReal * ($comision / 100.0);
        $pagoConductor = max(0.0, $costoReal - $comisionValor);

        return [
            'costo_real' => round($costoReal, 2),
            'comision_porcentaje' => round($comision, 2),
            'comision_valor' => round($comisionValor, 2),
            'pago_conductor' => round($pagoConductor, 2),
        ];
    }

    /**
     * Normaliza valores booleanos heterogeneos.
     */
    public static function toBool($value): bool
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
