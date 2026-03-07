<?php
/**
 * Repositorio de conductores.
 */

class DriverRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Obtiene IDs de conductores activos.
     * Nota: puede combinarse con Redis set active_drivers para alto rendimiento.
     */
    public function getActiveDriverIds(): array
    {
        $stmt = $this->db->query("SELECT DISTINCT usuario_id FROM detalles_conductor WHERE disponible = true");
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows ?: []);
    }
}
