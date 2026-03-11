-- ============================================================================
-- Migration 049: Ride Engine indexes + archivado de tracking
-- ============================================================================

-- Índices de viajes (tabla de negocio actual en Viax: solicitudes_servicio)
CREATE INDEX IF NOT EXISTS idx_solicitudes_estado
    ON public.solicitudes_servicio(estado);

CREATE INDEX IF NOT EXISTS idx_solicitudes_conductor
    ON public.solicitudes_servicio(conductor_id)
    WHERE conductor_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_solicitudes_cliente
    ON public.solicitudes_servicio(cliente_id);

-- Índice de tracking por viaje/tiempo
CREATE INDEX IF NOT EXISTS idx_trip_tracking_points_trip_ts
    ON public.trip_tracking_points(trip_id, "timestamp" DESC);

-- Tabla de archivo para retención > 30 días
CREATE TABLE IF NOT EXISTS public.trip_tracking_points_archive (
    id BIGINT PRIMARY KEY,
    trip_id BIGINT NOT NULL,
    lat DOUBLE PRECISION NOT NULL,
    lng DOUBLE PRECISION NOT NULL,
    speed FLOAT DEFAULT 0,
    heading FLOAT DEFAULT 0,
    "timestamp" BIGINT NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    archived_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_trip_tracking_points_archive_trip_ts
    ON public.trip_tracking_points_archive(trip_id, "timestamp" DESC);
