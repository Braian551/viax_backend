-- ============================================================================
-- Migration 048: Tracking Stream Upgrade
-- ============================================================================
-- Objetivo:
-- 1) Estandarizar almacenamiento de puntos para worker de Redis Streams.
-- 2) Mantener idempotencia para ejecución única/segura.

CREATE TABLE IF NOT EXISTS public.trip_tracking_points (
    id BIGSERIAL PRIMARY KEY,
    trip_id BIGINT NOT NULL,
    lat DOUBLE PRECISION NOT NULL,
    lng DOUBLE PRECISION NOT NULL,
    speed FLOAT DEFAULT 0,
    heading FLOAT DEFAULT 0,
    "timestamp" BIGINT NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_trip_tracking_points_trip
        FOREIGN KEY (trip_id)
        REFERENCES public.solicitudes_servicio(id)
        ON DELETE CASCADE
);

ALTER TABLE public.trip_tracking_points
    ADD COLUMN IF NOT EXISTS speed FLOAT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS heading FLOAT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'trip_tracking_points'
          AND column_name = 'timestamp'
          AND data_type <> 'bigint'
    ) THEN
        ALTER TABLE public.trip_tracking_points
        ALTER COLUMN "timestamp" DROP DEFAULT;

        ALTER TABLE public.trip_tracking_points
        ALTER COLUMN "timestamp" TYPE BIGINT
        USING EXTRACT(EPOCH FROM "timestamp")::BIGINT;

        ALTER TABLE public.trip_tracking_points
        ALTER COLUMN "timestamp" SET DEFAULT EXTRACT(EPOCH FROM NOW())::BIGINT;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_trip_tracking_points_trip_ts
    ON public.trip_tracking_points(trip_id, "timestamp" DESC);
