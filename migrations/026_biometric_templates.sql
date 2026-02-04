-- Migración 026: Sistema de Plantillas Biométricas
-- Fecha: 2026-01-01
-- Descripción: Almacenar solo encodings biométricos, no fotos

-- Agregar columna para plantilla biométrica (encoding JSON)
ALTER TABLE detalles_conductor 
ADD COLUMN IF NOT EXISTS plantilla_biometrica TEXT NULL COMMENT 'Encoding facial en formato JSON (128 valores float)';

-- Agregar columna para fecha de verificación biométrica
ALTER TABLE detalles_conductor 
ADD COLUMN IF NOT EXISTS fecha_verificacion_biometrica TIMESTAMP NULL COMMENT 'Fecha de última verificación biométrica exitosa';

-- Agregar índice para búsquedas por estado biométrico
CREATE INDEX IF NOT EXISTS idx_estado_biometrico ON detalles_conductor(estado_biometrico);

-- Tabla para almacenar plantillas de usuarios bloqueados (para comparación)
CREATE TABLE IF NOT EXISTS plantillas_biometricas_bloqueadas (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    plantilla TEXT NOT NULL COMMENT 'Encoding facial JSON',
    razon_bloqueo VARCHAR(255) NULL,
    fecha_bloqueo TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    activo BOOLEAN DEFAULT TRUE,
    CONSTRAINT fk_plantilla_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- Índice para búsquedas
CREATE INDEX IF NOT EXISTS idx_plantillas_activas ON plantillas_biometricas_bloqueadas(activo);

-- Comentario de la migración
COMMENT ON TABLE plantillas_biometricas_bloqueadas IS 'Almacena plantillas biométricas de usuarios bloqueados para prevenir re-registro';
