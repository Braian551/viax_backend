<?php
/**
 * Servicio de ubicación en tiempo real.
 *
 * Estrategia: guardar alta frecuencia en Redis y persistencia periódica en BD.
 */

class LocationService
{
    public function __construct(private LocationRepository $locationRepository)
    {
    }

    /**
     * Guarda ubicación rápida en Redis y opcionalmente persiste a BD.
     */
    public function updateDriverLocation(int $driverId, float $lat, float $lng, ?float $speed = null): void
    {
        $payload = json_encode([
            'lat' => $lat,
            'lng' => $lng,
            'speed' => $speed,
            'timestamp' => time(),
        ]);

        Cache::set('driver_location:' . $driverId, (string) $payload, 30);
        Cache::sAdd('active_drivers', (string) $driverId);

        // Persistencia eventual (1 de cada 10 actualizaciones aproximado).
        if (random_int(1, 10) === 1) {
            $this->locationRepository->updateDriverLocation($driverId, $lat, $lng);
        }
    }
}
