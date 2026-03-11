-- 046_canonical_trip_metrics.sql
-- Arquitectura canónica de métricas finales para evitar desalineaciones
-- entre cliente y conductor causadas por latencia, polling fuera de orden
-- y campos legacy que se pisan entre endpoints.

BEGIN;

ALTER TABLE solicitudes_servicio
    ADD COLUMN IF NOT EXISTS distance_final NUMERIC(10,3),
    ADD COLUMN IF NOT EXISTS duration_final INTEGER,
    ADD COLUMN IF NOT EXISTS metrics_locked BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS finalized_at TIMESTAMP NULL;

-- Compatibilidad con diseños que esperan un alias en inglés de precio final.
-- Si ya existe, no se modifica.
ALTER TABLE solicitudes_servicio
    ADD COLUMN IF NOT EXISTS price_final NUMERIC(12,2);

COMMENT ON COLUMN solicitudes_servicio.distance_final IS
    'Distancia final canónica e inmutable del viaje. Evita inconsistencias por latencia.';
COMMENT ON COLUMN solicitudes_servicio.duration_final IS
    'Duración final canónica en segundos. No debe recalcularse tras cerrar el viaje.';
COMMENT ON COLUMN solicitudes_servicio.price_final IS
    'Precio final canónico (alias en inglés) para estandarizar endpoints.';
COMMENT ON COLUMN solicitudes_servicio.metrics_locked IS
    'Cuando TRUE, las métricas finales quedan congeladas y no se aceptan sobrescrituras tardías.';
COMMENT ON COLUMN solicitudes_servicio.finalized_at IS
    'Marca temporal de cierre canónico del viaje.';

CREATE INDEX IF NOT EXISTS idx_solicitudes_metrics_locked
    ON solicitudes_servicio (metrics_locked, estado);

CREATE INDEX IF NOT EXISTS idx_solicitudes_finalized_at
    ON solicitudes_servicio (finalized_at DESC NULLS LAST);

COMMIT;
