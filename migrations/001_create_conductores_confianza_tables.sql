
-- ============================================================================
-- MIGRACIÓN: Sistema de Conductores de Confianza (PostgreSQL)
-- Fecha: 2025-12-02
-- Descripción: Tablas para gestionar conductores favoritos y puntaje de confianza
-- Postgres-ready migration
-- ============================================================================

-- ============================================================================
-- TABLA 1: conductores_favoritos
-- Permite a los usuarios marcar conductores como favoritos
-- ============================================================================
CREATE TABLE IF NOT EXISTS conductores_favoritos (
  id BIGSERIAL PRIMARY KEY,
  usuario_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  conductor_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  es_favorito BOOLEAN DEFAULT TRUE,
  fecha_marcado TIMESTAMPTZ DEFAULT now(),
  fecha_desmarcado TIMESTAMPTZ,
  CONSTRAINT ux_conductores_favoritos_usuario_conductor UNIQUE (usuario_id, conductor_id)
);

-- Índices adicionales para consultas frecuentes
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_conductor_favoritos') THEN
    CREATE INDEX idx_conductor_favoritos ON conductores_favoritos (conductor_id);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_es_favorito') THEN
    CREATE INDEX idx_es_favorito ON conductores_favoritos (es_favorito);
  END IF;
END
$$;

-- ============================================================================
-- TABLA 2: historial_confianza
-- Almacena el historial de viajes entre usuario-conductor para calcular confianza
-- ============================================================================
CREATE TABLE IF NOT EXISTS historial_confianza (
  id BIGSERIAL PRIMARY KEY,
  usuario_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  conductor_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  total_viajes INT DEFAULT 0,
  viajes_completados INT DEFAULT 0,
  viajes_cancelados INT DEFAULT 0,
  suma_calificaciones_conductor NUMERIC(10,2) DEFAULT 0,
  suma_calificaciones_usuario NUMERIC(10,2) DEFAULT 0,
  total_calificaciones INT DEFAULT 0,
  ultimo_viaje_fecha TIMESTAMPTZ,
  score_confianza NUMERIC(5,2) DEFAULT 0.00,
  zona_frecuente_lat NUMERIC(10,8),
  zona_frecuente_lng NUMERIC(11,8),
  creado_en TIMESTAMPTZ DEFAULT now(),
  actualizado_en TIMESTAMPTZ DEFAULT now(),
  CONSTRAINT ux_historial_confianza_usuario_conductor UNIQUE (usuario_id, conductor_id)
);

-- Índices adicionales para optimización de consultas de confianza
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_conductor_confianza') THEN
    CREATE INDEX idx_conductor_confianza ON historial_confianza (conductor_id);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_score_confianza') THEN
    CREATE INDEX idx_score_confianza ON historial_confianza (score_confianza);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_zona_frecuente') THEN
    CREATE INDEX idx_zona_frecuente ON historial_confianza (zona_frecuente_lat, zona_frecuente_lng);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_confianza_score_viajes') THEN
    -- El índice usa orden descendente para priorizar score y total_viajes mayores
    CREATE INDEX idx_confianza_score_viajes ON historial_confianza (conductor_id, score_confianza DESC, total_viajes DESC);
  END IF;
END
$$;

-- ============================================================================
-- Trigger para actualizar `actualizado_en` en las tablas que lo tienen
-- ============================================================================
CREATE OR REPLACE FUNCTION set_actualizado_en()
RETURNS TRIGGER AS $$
BEGIN
  NEW.actualizado_en = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger para `historial_confianza`
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_historial_confianza_actualizado_en') THEN
    CREATE TRIGGER trg_historial_confianza_actualizado_en
      BEFORE UPDATE ON historial_confianza
      FOR EACH ROW
      EXECUTE FUNCTION set_actualizado_en();
  END IF;
END
$$;

-- ============================================================================
-- VISTA: conductores_confianza_ranking
-- Vista para obtener ranking de conductores por confianza para un usuario
-- ============================================================================
CREATE OR REPLACE VIEW conductores_confianza_ranking AS
SELECT
  hc.usuario_id,
  hc.conductor_id,
  u.nombre AS conductor_nombre,
  u.apellido AS conductor_apellido,
  dc.calificacion_promedio,
  dc.total_viajes AS total_viajes_conductor,
  hc.total_viajes AS viajes_con_usuario,
  hc.viajes_completados,
  hc.score_confianza,
  cf.es_favorito,
  CASE WHEN cf.es_favorito THEN 100 ELSE 0 END AS bonus_favorito,
  (hc.score_confianza + CASE WHEN cf.es_favorito THEN 100 ELSE 0 END) AS score_total
FROM historial_confianza hc
INNER JOIN usuarios u ON hc.conductor_id = u.id
INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
LEFT JOIN conductores_favoritos cf ON hc.usuario_id = cf.usuario_id
  AND hc.conductor_id = cf.conductor_id
  AND cf.es_favorito = TRUE
WHERE dc.estado_verificacion = 'aprobado'
ORDER BY score_total DESC;

-- ============================================================================
-- Nota:
-- - Esta migración está orientada a PostgreSQL (tipos BIGSERIAL, BOOLEAN, TIMESTAMPTZ, NUMERIC).
-- - A diferencia de MySQL, Postgres no soporta `ON UPDATE CURRENT_TIMESTAMP` en la definición de columna,
--   por lo que se creó un trigger genérico `set_actualizado_en()` y se asocia a la tabla `historial_confianza`.
-- - El administrador del esquema puede decidir agregar el trigger también en `conductores_favoritos` u otras tablas
--   que necesiten mantener `actualizado_en` automáticamente.
-- - Si necesitan `CONCURRENTLY` para crear índices en producción con alta carga, se puede ajustar la migracion
--   (CREATE INDEX CONCURRENTLY) y/o usar herramientas de migración para hacerlo sin bloquear.
-- ============================================================================
 

