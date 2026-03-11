-- ============================================================================
-- Migration 047: Tracking phase 2 storage model
-- ============================================================================
-- Tabla liviana para persistencia de puntos GPS canónicos por viaje.

CREATE TABLE IF NOT EXISTS public.trip_tracking_points (
    id BIGSERIAL PRIMARY KEY,
    trip_id BIGINT NOT NULL,
    lat DOUBLE PRECISION NOT NULL,
    lng DOUBLE PRECISION NOT NULL,
    "timestamp" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    CONSTRAINT fk_trip_tracking_points_trip
        FOREIGN KEY (trip_id)
        REFERENCES public.solicitudes_servicio(id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_trip_tracking_points_trip_ts
    ON public.trip_tracking_points(trip_id, "timestamp" DESC);

COMMENT ON TABLE public.trip_tracking_points IS
    'Puntos GPS crudos de tracking (fase 2) con escritura asíncrona desde Redis queue.';
