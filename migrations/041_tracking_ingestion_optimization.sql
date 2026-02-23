-- ============================================================================
-- Migration 041: Optimización de ingestión de tracking en tiempo real
-- ============================================================================
-- Objetivos:
-- 1) Reducir consultas de lectura del último punto (snapshot O(1)).
-- 2) Mejorar performance de lectura/escritura del historial realtime.
-- 3) Soportar estrategia de ingesta por lotes desde la app móvil.

CREATE TABLE IF NOT EXISTS public.viaje_tracking_snapshot (
    solicitud_id BIGINT PRIMARY KEY,
    conductor_id BIGINT NOT NULL,
    latitud DECIMAL(10, 8) NOT NULL,
    longitud DECIMAL(11, 8) NOT NULL,
    distancia_acumulada_km DECIMAL(10, 3) DEFAULT 0,
    tiempo_transcurrido_seg INTEGER DEFAULT 0,
    precio_parcial DECIMAL(12, 2) DEFAULT 0,
    fase_viaje VARCHAR(30) DEFAULT 'hacia_destino',
    actualizado_en TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tracking_snapshot_solicitud FOREIGN KEY (solicitud_id)
        REFERENCES public.solicitudes_servicio(id) ON DELETE CASCADE,
    CONSTRAINT fk_tracking_snapshot_conductor FOREIGN KEY (conductor_id)
        REFERENCES public.usuarios(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tracking_snapshot_conductor
    ON public.viaje_tracking_snapshot(conductor_id);

CREATE INDEX IF NOT EXISTS idx_tracking_snapshot_updated
    ON public.viaje_tracking_snapshot(actualizado_en DESC);

CREATE INDEX IF NOT EXISTS idx_tracking_rt_solicitud_tiempo_id
    ON public.viaje_tracking_realtime(solicitud_id, tiempo_transcurrido_seg DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_tracking_rt_solicitud_servidor
    ON public.viaje_tracking_realtime(solicitud_id, timestamp_servidor DESC);

COMMENT ON TABLE public.viaje_tracking_snapshot IS
    'Estado caliente del tracking por viaje (último punto), usado para consultas rápidas sin escanear historial.';
