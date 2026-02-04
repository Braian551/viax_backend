-- ============================================================================
-- Migration 034: Sistema de Tracking en Tiempo Real del Viaje
-- ============================================================================
-- 
-- Esta migración crea las estructuras necesarias para rastrear los viajes
-- en tiempo real, similar a Uber/Didi.
--
-- El sistema captura puntos GPS cada 5 segundos durante el viaje,
-- calculando distancia real recorrida y tiempo transcurrido.
-- 
-- Esto permite:
-- 1. Calcular la tarifa REAL basada en km/tiempo reales
-- 2. Sincronizar valores entre conductor y cliente
-- 3. Detectar desvíos de ruta o tráfico
-- 4. Auditoría completa del viaje
--
-- ============================================================================

-- ============================================================================
-- TABLA 1: viaje_tracking_realtime
-- Almacena cada punto GPS durante el viaje activo
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.viaje_tracking_realtime (
    id BIGSERIAL PRIMARY KEY,
    
    -- Referencia al viaje
    solicitud_id BIGINT NOT NULL,
    conductor_id BIGINT NOT NULL,
    
    -- Datos GPS del punto
    latitud DECIMAL(10, 8) NOT NULL,
    longitud DECIMAL(11, 8) NOT NULL,
    precision_gps DECIMAL(6, 2) DEFAULT NULL,  -- Precisión en metros
    altitud DECIMAL(8, 2) DEFAULT NULL,        -- Altitud opcional
    velocidad DECIMAL(6, 2) DEFAULT 0,         -- Velocidad en km/h
    bearing DECIMAL(6, 2) DEFAULT 0,           -- Dirección/rumbo
    
    -- Valores ACUMULADOS hasta este punto
    distancia_acumulada_km DECIMAL(10, 3) DEFAULT 0,    -- Distancia total hasta este punto
    tiempo_transcurrido_seg INTEGER DEFAULT 0,           -- Segundos desde inicio del viaje
    
    -- Distancia desde el punto anterior (para cálculos incrementales)
    distancia_desde_anterior_m DECIMAL(10, 2) DEFAULT 0, -- Metros desde punto anterior
    
    -- Precio calculado hasta este punto (para mostrar en UI)
    precio_parcial DECIMAL(12, 2) DEFAULT 0,
    
    -- Metadatos
    timestamp_gps TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP, -- Momento del GPS
    timestamp_servidor TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP, -- Momento recibido
    
    -- Indica si el viaje está en fase de recogida o hacia destino
    fase_viaje VARCHAR(30) DEFAULT 'hacia_destino', -- 'hacia_recogida', 'hacia_destino'
    
    -- Si hubo algún evento especial en este punto
    evento VARCHAR(50) DEFAULT NULL, -- 'inicio', 'parada', 'desvio', 'trafico', etc.
    
    -- Para sincronización offline
    sincronizado BOOLEAN DEFAULT TRUE,
    
    -- Índices para búsquedas rápidas
    CONSTRAINT fk_tracking_solicitud FOREIGN KEY (solicitud_id) 
        REFERENCES public.solicitudes_servicio(id) ON DELETE CASCADE,
    CONSTRAINT fk_tracking_conductor FOREIGN KEY (conductor_id) 
        REFERENCES public.usuarios(id) ON DELETE CASCADE
);

-- Índice principal: búsqueda por viaje + orden temporal
CREATE INDEX IF NOT EXISTS idx_tracking_solicitud_tiempo 
    ON public.viaje_tracking_realtime(solicitud_id, timestamp_gps DESC);

-- Índice para obtener el último punto de un viaje
CREATE INDEX IF NOT EXISTS idx_tracking_solicitud_id 
    ON public.viaje_tracking_realtime(solicitud_id);

-- Índice para obtener puntos por conductor
CREATE INDEX IF NOT EXISTS idx_tracking_conductor_id 
    ON public.viaje_tracking_realtime(conductor_id);

-- Comentarios
COMMENT ON TABLE public.viaje_tracking_realtime IS 
    'Tracking GPS en tiempo real durante viajes activos. Cada fila es un punto GPS con acumulados.';
COMMENT ON COLUMN public.viaje_tracking_realtime.distancia_acumulada_km IS 
    'Distancia TOTAL recorrida desde inicio del viaje hasta este punto (km)';
COMMENT ON COLUMN public.viaje_tracking_realtime.tiempo_transcurrido_seg IS 
    'Tiempo TOTAL desde que inició el viaje hasta este punto (segundos)';
COMMENT ON COLUMN public.viaje_tracking_realtime.precio_parcial IS 
    'Precio calculado acumulado hasta este punto (para mostrar en UI cliente/conductor)';


-- ============================================================================
-- TABLA 2: viaje_resumen_tracking
-- Resumen consolidado del tracking por viaje (para consultas rápidas)
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.viaje_resumen_tracking (
    id BIGSERIAL PRIMARY KEY,
    
    solicitud_id BIGINT NOT NULL UNIQUE,
    
    -- Totales REALES del viaje
    distancia_real_km DECIMAL(10, 3) DEFAULT 0,
    tiempo_real_minutos INTEGER DEFAULT 0,
    
    -- Comparación con estimados
    distancia_estimada_km DECIMAL(10, 3) DEFAULT 0,
    tiempo_estimado_minutos INTEGER DEFAULT 0,
    
    -- Desviaciones
    diferencia_distancia_km DECIMAL(10, 3) DEFAULT 0,  -- real - estimada (+ significa más km)
    diferencia_tiempo_min INTEGER DEFAULT 0,            -- real - estimado
    porcentaje_desvio_distancia DECIMAL(6, 2) DEFAULT 0, -- % de diferencia
    
    -- Precios
    precio_estimado DECIMAL(12, 2) DEFAULT 0,
    precio_final_calculado DECIMAL(12, 2) DEFAULT 0,   -- Calculado por tracking
    precio_final_aplicado DECIMAL(12, 2) DEFAULT 0,    -- El que se cobró realmente
    
    -- Estadísticas del viaje
    velocidad_promedio_kmh DECIMAL(6, 2) DEFAULT 0,
    velocidad_maxima_kmh DECIMAL(6, 2) DEFAULT 0,
    total_puntos_gps INTEGER DEFAULT 0,
    
    -- Detección de anomalías
    tiene_desvio_ruta BOOLEAN DEFAULT FALSE,
    km_desvio_detectado DECIMAL(8, 3) DEFAULT 0,
    
    -- Timestamps
    inicio_viaje_real TIMESTAMP WITHOUT TIME ZONE,
    fin_viaje_real TIMESTAMP WITHOUT TIME ZONE,
    creado_en TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_resumen_solicitud FOREIGN KEY (solicitud_id) 
        REFERENCES public.solicitudes_servicio(id) ON DELETE CASCADE
);

COMMENT ON TABLE public.viaje_resumen_tracking IS 
    'Resumen consolidado del tracking de cada viaje para consultas rápidas y cálculo de tarifas';


-- ============================================================================
-- AGREGAR COLUMNAS A solicitudes_servicio (si no existen)
-- ============================================================================

DO $$
BEGIN
    -- Columna para distancia REAL recorrida
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'distancia_recorrida'
    ) THEN
        ALTER TABLE public.solicitudes_servicio 
        ADD COLUMN distancia_recorrida DECIMAL(10, 3) DEFAULT NULL;
        
        COMMENT ON COLUMN public.solicitudes_servicio.distancia_recorrida IS 
            'Distancia REAL recorrida durante el viaje (km) - calculada por GPS tracking';
    END IF;

    -- Columna para tiempo REAL transcurrido
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'tiempo_transcurrido'
    ) THEN
        ALTER TABLE public.solicitudes_servicio 
        ADD COLUMN tiempo_transcurrido INTEGER DEFAULT NULL;
        
        COMMENT ON COLUMN public.solicitudes_servicio.tiempo_transcurrido IS 
            'Tiempo REAL transcurrido durante el viaje (segundos)';
    END IF;

    -- Columna para saber si el precio final fue ajustado por tracking
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'precio_ajustado_por_tracking'
    ) THEN
        ALTER TABLE public.solicitudes_servicio 
        ADD COLUMN precio_ajustado_por_tracking BOOLEAN DEFAULT FALSE;
        
        COMMENT ON COLUMN public.solicitudes_servicio.precio_ajustado_por_tracking IS 
            'Indica si el precio final fue recalculado basado en tracking GPS real';
    END IF;

    -- Columna para indicar si hubo desvío significativo
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'tuvo_desvio_ruta'
    ) THEN
        ALTER TABLE public.solicitudes_servicio 
        ADD COLUMN tuvo_desvio_ruta BOOLEAN DEFAULT FALSE;
        
        COMMENT ON COLUMN public.solicitudes_servicio.tuvo_desvio_ruta IS 
            'Indica si el viaje tuvo un desvío significativo de la ruta original';
    END IF;
END $$;


-- ============================================================================
-- FUNCIÓN: Calcular precio final basado en tracking
-- ============================================================================

CREATE OR REPLACE FUNCTION public.calcular_precio_por_tracking(
    p_solicitud_id BIGINT
) RETURNS JSONB
LANGUAGE plpgsql
AS $$
DECLARE
    v_resumen RECORD;
    v_config RECORD;
    v_tipo_vehiculo VARCHAR(50);
    v_precio_base DECIMAL(12,2);
    v_precio_distancia DECIMAL(12,2);
    v_precio_tiempo DECIMAL(12,2);
    v_recargos DECIMAL(12,2) := 0;
    v_precio_total DECIMAL(12,2);
    v_tarifa_minima DECIMAL(12,2);
    v_resultado JSONB;
BEGIN
    -- Obtener resumen del tracking
    SELECT * INTO v_resumen
    FROM viaje_resumen_tracking
    WHERE solicitud_id = p_solicitud_id;
    
    IF v_resumen IS NULL THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'No hay datos de tracking para este viaje'
        );
    END IF;
    
    -- Obtener tipo de vehículo del viaje
    SELECT tipo_servicio INTO v_tipo_vehiculo
    FROM solicitudes_servicio
    WHERE id = p_solicitud_id;
    
    -- Obtener configuración de precios
    SELECT * INTO v_config
    FROM configuracion_precios
    WHERE tipo_vehiculo = v_tipo_vehiculo AND activo = 1
    LIMIT 1;
    
    IF v_config IS NULL THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'No hay configuración de precios para este tipo de vehículo'
        );
    END IF;
    
    -- Calcular componentes del precio
    v_precio_base := COALESCE(v_config.tarifa_base, 0);
    v_precio_distancia := v_resumen.distancia_real_km * COALESCE(v_config.costo_por_km, 0);
    v_precio_tiempo := (v_resumen.tiempo_real_minutos) * COALESCE(v_config.costo_por_minuto, 0);
    
    -- Calcular total
    v_precio_total := v_precio_base + v_precio_distancia + v_precio_tiempo + v_recargos;
    
    -- Aplicar tarifa mínima
    v_tarifa_minima := COALESCE(v_config.tarifa_minima, 0);
    IF v_precio_total < v_tarifa_minima THEN
        v_precio_total := v_tarifa_minima;
    END IF;
    
    -- Aplicar tarifa máxima si existe
    IF v_config.tarifa_maxima IS NOT NULL AND v_precio_total > v_config.tarifa_maxima THEN
        v_precio_total := v_config.tarifa_maxima;
    END IF;
    
    RETURN jsonb_build_object(
        'success', true,
        'precio_calculado', v_precio_total,
        'desglose', jsonb_build_object(
            'tarifa_base', v_precio_base,
            'precio_distancia', v_precio_distancia,
            'precio_tiempo', v_precio_tiempo,
            'recargos', v_recargos,
            'distancia_km', v_resumen.distancia_real_km,
            'tiempo_min', v_resumen.tiempo_real_minutos
        ),
        'diferencia_estimado', v_precio_total - v_resumen.precio_estimado
    );
END;
$$;

COMMENT ON FUNCTION public.calcular_precio_por_tracking IS 
    'Calcula el precio final de un viaje basado en los datos REALES del tracking GPS';


-- ============================================================================
-- TRIGGER: Actualizar resumen cuando se inserta un punto de tracking
-- ============================================================================

CREATE OR REPLACE FUNCTION public.actualizar_resumen_tracking()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_total_puntos INTEGER;
    v_velocidad_max DECIMAL(6,2);
    v_inicio TIMESTAMP;
BEGIN
    -- Obtener estadísticas del viaje
    SELECT 
        COUNT(*),
        MAX(velocidad),
        MIN(timestamp_gps)
    INTO v_total_puntos, v_velocidad_max, v_inicio
    FROM viaje_tracking_realtime
    WHERE solicitud_id = NEW.solicitud_id;
    
    -- Insertar o actualizar resumen
    INSERT INTO viaje_resumen_tracking (
        solicitud_id,
        distancia_real_km,
        tiempo_real_minutos,
        total_puntos_gps,
        velocidad_maxima_kmh,
        velocidad_promedio_kmh,
        inicio_viaje_real,
        actualizado_en
    ) VALUES (
        NEW.solicitud_id,
        NEW.distancia_acumulada_km,
        CEIL(NEW.tiempo_transcurrido_seg / 60.0),
        v_total_puntos,
        v_velocidad_max,
        CASE WHEN NEW.tiempo_transcurrido_seg > 0 
             THEN (NEW.distancia_acumulada_km * 3600 / NEW.tiempo_transcurrido_seg) 
             ELSE 0 END,
        v_inicio,
        CURRENT_TIMESTAMP
    )
    ON CONFLICT (solicitud_id) DO UPDATE SET
        distancia_real_km = EXCLUDED.distancia_real_km,
        tiempo_real_minutos = EXCLUDED.tiempo_real_minutos,
        total_puntos_gps = EXCLUDED.total_puntos_gps,
        velocidad_maxima_kmh = GREATEST(viaje_resumen_tracking.velocidad_maxima_kmh, EXCLUDED.velocidad_maxima_kmh),
        velocidad_promedio_kmh = EXCLUDED.velocidad_promedio_kmh,
        actualizado_en = CURRENT_TIMESTAMP;
    
    RETURN NEW;
END;
$$;

-- Crear el trigger
DROP TRIGGER IF EXISTS trg_actualizar_resumen_tracking ON public.viaje_tracking_realtime;
CREATE TRIGGER trg_actualizar_resumen_tracking
    AFTER INSERT ON public.viaje_tracking_realtime
    FOR EACH ROW
    EXECUTE FUNCTION public.actualizar_resumen_tracking();


-- ============================================================================
-- VISTA: Viajes con tracking para consultas
-- ============================================================================

CREATE OR REPLACE VIEW public.viajes_con_tracking AS
SELECT 
    s.id,
    s.uuid_solicitud,
    s.cliente_id,
    s.tipo_servicio,
    s.estado,
    s.direccion_recogida,
    s.direccion_destino,
    s.distancia_estimada,
    s.tiempo_estimado,
    s.precio_estimado,
    s.precio_final,
    s.distancia_recorrida,
    s.tiempo_transcurrido,
    s.precio_ajustado_por_tracking,
    -- Datos del tracking
    r.distancia_real_km AS tracking_distancia_km,
    r.tiempo_real_minutos AS tracking_tiempo_min,
    r.velocidad_promedio_kmh,
    r.total_puntos_gps,
    r.tiene_desvio_ruta,
    -- Diferencias calculadas
    COALESCE(r.distancia_real_km, 0) - COALESCE(s.distancia_estimada, 0) AS diferencia_distancia_km,
    COALESCE(r.tiempo_real_minutos, 0) - COALESCE(s.tiempo_estimado, 0) AS diferencia_tiempo_min,
    CASE 
        WHEN s.distancia_estimada > 0 THEN 
            ROUND(((COALESCE(r.distancia_real_km, 0) - s.distancia_estimada) / s.distancia_estimada * 100)::numeric, 2)
        ELSE 0
    END AS porcentaje_desvio,
    -- Conductor asignado
    ac.conductor_id
FROM solicitudes_servicio s
LEFT JOIN viaje_resumen_tracking r ON s.id = r.solicitud_id
LEFT JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id AND ac.estado != 'cancelado';

COMMENT ON VIEW public.viajes_con_tracking IS 
    'Vista que une solicitudes con su tracking para análisis de viajes';


-- ============================================================================
-- ÍNDICES ADICIONALES PARA PERFORMANCE
-- ============================================================================

-- Índice para búsquedas por fecha en tracking
CREATE INDEX IF NOT EXISTS idx_tracking_timestamp 
    ON public.viaje_tracking_realtime(timestamp_gps);

-- Índice parcial para viajes que tuvieron desvío
CREATE INDEX IF NOT EXISTS idx_resumen_con_desvio 
    ON public.viaje_resumen_tracking(solicitud_id) 
    WHERE tiene_desvio_ruta = TRUE;

