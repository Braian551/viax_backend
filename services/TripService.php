<?php
/**
 * Servicio de viajes: reglas de negocio y transición de estados.
 */

class TripService
{
    private const VALID_TRANSITIONS = [
        'requested' => ['driver_assigned', 'trip_cancelled'],
        'driver_assigned' => ['driver_arriving', 'trip_cancelled'],
        'driver_arriving' => ['trip_started', 'trip_cancelled'],
        'trip_started' => ['trip_completed', 'trip_cancelled'],
        'trip_completed' => [],
        'trip_cancelled' => [],
    ];

    public function isValidTransition(string $from, string $to): bool
    {
        return in_array($to, self::VALID_TRANSITIONS[$from] ?? [], true);
    }
}
