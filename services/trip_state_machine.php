<?php
/**
 * Máquina de estados de viaje.
 */

class TripStateMachine
{
    public const REQUESTED = 'REQUESTED';
    public const DRIVER_ASSIGNED = 'DRIVER_ASSIGNED';
    public const DRIVER_ARRIVED = 'DRIVER_ARRIVED';
    public const TRIP_STARTED = 'TRIP_STARTED';
    public const TRIP_IN_PROGRESS = 'TRIP_IN_PROGRESS';
    public const TRIP_COMPLETED = 'TRIP_COMPLETED';
    public const TRIP_CANCELLED = 'TRIP_CANCELLED';

    /**
     * @var array<string,list<string>>
     */
    private static array $transitions = [
        // Flujo estricto requerido por producto:
        // requested -> driver_assigned -> driver_arrived -> trip_started -> trip_in_progress -> trip_completed
        self::REQUESTED => [self::DRIVER_ASSIGNED, self::TRIP_CANCELLED],
        self::DRIVER_ASSIGNED => [self::DRIVER_ARRIVED, self::TRIP_CANCELLED],
        // Compatibilidad temporal: algunos clientes legacy saltan directo a en_curso.
        self::DRIVER_ARRIVED => [self::TRIP_STARTED, self::TRIP_IN_PROGRESS, self::TRIP_CANCELLED],
        self::TRIP_STARTED => [self::TRIP_IN_PROGRESS, self::TRIP_CANCELLED],
        self::TRIP_IN_PROGRESS => [self::TRIP_COMPLETED, self::TRIP_CANCELLED],
        self::TRIP_COMPLETED => [],
        self::TRIP_CANCELLED => [],
    ];

    /**
     * @var array<string,string>
     */
    private static array $aliases = [
        'requested' => self::REQUESTED,
        'pendiente' => self::REQUESTED,

        'driver_assigned' => self::DRIVER_ASSIGNED,
        'asignado' => self::DRIVER_ASSIGNED,
        'aceptada' => self::DRIVER_ASSIGNED,
        'aceptado' => self::DRIVER_ASSIGNED,

        'driver_arrived' => self::DRIVER_ARRIVED,
        'conductor_llego' => self::DRIVER_ARRIVED,

        'trip_started' => self::TRIP_STARTED,
        'recogido' => self::TRIP_STARTED,

        'trip_in_progress' => self::TRIP_IN_PROGRESS,
        'en_curso' => self::TRIP_IN_PROGRESS,
        'en_viaje' => self::TRIP_IN_PROGRESS,
        'iniciado' => self::TRIP_IN_PROGRESS,

        'trip_completed' => self::TRIP_COMPLETED,
        'completada' => self::TRIP_COMPLETED,
        'completado' => self::TRIP_COMPLETED,
        'entregado' => self::TRIP_COMPLETED,
        'finalizada' => self::TRIP_COMPLETED,
        'finalizado' => self::TRIP_COMPLETED,

        'trip_cancelled' => self::TRIP_CANCELLED,
        'cancelada' => self::TRIP_CANCELLED,
        'cancelado' => self::TRIP_CANCELLED,
        'rechazada' => self::TRIP_CANCELLED,
        'rechazado' => self::TRIP_CANCELLED,
        'rejected' => self::TRIP_CANCELLED,
    ];

    public static function normalizeState(string $state): string
    {
        $raw = strtolower(trim($state));
        if ($raw === '') {
            throw new Exception('Estado de viaje vacío');
        }

        if (isset(self::$aliases[$raw])) {
            return self::$aliases[$raw];
        }

        $upper = strtoupper(trim($state));
        if (isset(self::$transitions[$upper])) {
            return $upper;
        }

        throw new Exception('Estado de viaje no reconocido: ' . $state);
    }

    public static function canTransition(string $from, string $to): bool
    {
        $from = self::normalizeState($from);
        $to = self::normalizeState($to);

        if ($from === $to) {
            return true;
        }

        if (!isset(self::$transitions[$from])) {
            return false;
        }

        return in_array($to, self::$transitions[$from], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            $fromCanonical = self::normalizeState($from);
            $toCanonical = self::normalizeState($to);
            throw new Exception('Transición de estado inválida: ' . $fromCanonical . ' -> ' . $toCanonical);
        }
    }

    public static function validateTransition(string $from, string $to): void
    {
        self::assertTransition($from, $to);
    }
}
