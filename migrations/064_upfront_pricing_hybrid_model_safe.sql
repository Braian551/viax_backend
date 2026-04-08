-- =====================================================
-- MIGRACION 064: Pricing hibrido (safe, sin mutacion)
-- =====================================================
-- Replica las columnas de upfront pricing sin UPDATE/DELETE/TRUNCATE
-- para cumplir politica de despliegue seguro.

BEGIN;

ALTER TABLE solicitudes_servicio
    ADD COLUMN IF NOT EXISTS precio_fijo NUMERIC(12,2),
    ADD COLUMN IF NOT EXISTS precio_congelado BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS precio_calculado_real NUMERIC(12,2),
    ADD COLUMN IF NOT EXISTS desviacion_porcentaje NUMERIC(8,2) NOT NULL DEFAULT 0;

COMMENT ON COLUMN solicitudes_servicio.precio_fijo IS
    'Precio upfront mostrado al usuario al crear el viaje.';
COMMENT ON COLUMN solicitudes_servicio.precio_congelado IS
    'Si TRUE, el cobro al usuario usa precio_fijo salvo recalc forzado.';
COMMENT ON COLUMN solicitudes_servicio.precio_calculado_real IS
    'Precio real calculado con distancia/tiempo y recargos del tracking.';
COMMENT ON COLUMN solicitudes_servicio.desviacion_porcentaje IS
    'Diferencia porcentual entre precio_fijo y precio_real.';

CREATE INDEX IF NOT EXISTS idx_solicitudes_precio_congelado
    ON solicitudes_servicio (precio_congelado, estado);

COMMIT;
