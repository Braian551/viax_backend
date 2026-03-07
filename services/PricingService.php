<?php
/**
 * Servicio de cálculo de precio.
 *
 * Mantiene cálculos en PHP para evitar cambiar contratos actuales.
 */

class PricingService
{
    public function estimate(float $distanceKm, int $durationMin): float
    {
        $base = 5000.0;
        $perKm = 1800.0;
        $perMin = 250.0;

        return round($base + ($distanceKm * $perKm) + ($durationMin * $perMin), 2);
    }
}
