<?php
/**
 * Máquina de estados de viaje.
 */

class TripStateMachine
{
    public const REQUESTED = 'REQUESTED';
    public const DRIVER_ASSIGNED = 'DRIVER_ASSIGNED';
    public const DRIVER_EN_ROUTE = 'DRIVER_EN_ROUTE';
    public const DRIVER_ARRIVED = 'DRIVER_ARRIVED';
    public const TRIP_STARTED = 'TRIP_STARTED';
    public const TRIP_COMPLETED = 'TRIP_COMPLETED';
    public const TRIP_CANCELLED = 'TRIP_CANCELLED';

    /**
     * @var array<string,list<string>>
     */
    private static array $transitions = [
        // Compatibilidad: algunos clientes legacy saltan etapas intermedias.
        self::REQUESTED => [self::DRIVER_ASSIGNED, self::DRIVER_ARRIVED, self::TRIP_STARTED, self::TRIP_CANCELLED],
        self::DRIVER_ASSIGNED => [self::DRIVER_EN_ROUTE, self::DRIVER_ARRIVED, self::TRIP_STARTED, self::TRIP_CANCELLED],
        self::DRIVER_EN_ROUTE => [self::DRIVER_ARRIVED, self::TRIP_STARTED, self::TRIP_CANCELLED],
        self::DRIVER_ARRIVED => [self::TRIP_STARTED, self::TRIP_CANCELLED],
        self::TRIP_STARTED => [self::TRIP_COMPLETED, self::TRIP_CANCELLED],
        self::TRIP_COMPLETED => [],
        self::TRIP_CANCELLED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if (!isset(self::$transitions[$from])) {
            return false;
        }

        return in_array($to, self::$transitions[$from], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new Exception('Transición inválida de estado: ' . $from . ' -> ' . $to);
        }
    }
}
