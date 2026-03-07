<?php
/**
 * Repositorio de ubicación.
 */

class LocationRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Persiste ubicación de conductor en BD (uso periódico, no cada ping GPS).
     */
    public function updateDriverLocation(int $driverId, float $lat, float $lng): bool
    {
        $stmt = $this->db->prepare('UPDATE detalles_conductor SET latitud_actual = ?, longitud_actual = ? WHERE usuario_id = ?');
        return $stmt->execute([$lat, $lng, $driverId]);
    }
}
