-- Migration 013: Create chat messages table
-- Date: 2025-12-21
-- Description: Creates table for real-time chat messages between drivers and clients

-- Tabla de mensajes de chat entre conductor y cliente durante un viaje
CREATE TABLE IF NOT EXISTS mensajes_chat (
    id SERIAL PRIMARY KEY,
    solicitud_id INTEGER NOT NULL REFERENCES solicitudes_servicio(id) ON DELETE CASCADE,
    remitente_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    destinatario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo_remitente VARCHAR(20) NOT NULL CHECK (tipo_remitente IN ('cliente', 'conductor')),
    mensaje TEXT NOT NULL,
    tipo_mensaje VARCHAR(20) DEFAULT 'texto' CHECK (tipo_mensaje IN ('texto', 'imagen', 'ubicacion', 'audio', 'sistema')),
    leido BOOLEAN DEFAULT FALSE,
    leido_en TIMESTAMP NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    activo BOOLEAN DEFAULT TRUE
);

-- Índices para optimizar consultas
CREATE INDEX IF NOT EXISTS idx_mensajes_solicitud ON mensajes_chat(solicitud_id);
CREATE INDEX IF NOT EXISTS idx_mensajes_remitente ON mensajes_chat(remitente_id);
CREATE INDEX IF NOT EXISTS idx_mensajes_destinatario ON mensajes_chat(destinatario_id);
CREATE INDEX IF NOT EXISTS idx_mensajes_fecha ON mensajes_chat(fecha_creacion DESC);
CREATE INDEX IF NOT EXISTS idx_mensajes_no_leidos ON mensajes_chat(destinatario_id, leido) WHERE leido = FALSE;

-- Trigger para actualizar fecha_actualizacion automáticamente
CREATE OR REPLACE FUNCTION update_mensajes_chat_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.fecha_actualizacion = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_update_mensajes_chat ON mensajes_chat;
CREATE TRIGGER trigger_update_mensajes_chat
    BEFORE UPDATE ON mensajes_chat
    FOR EACH ROW
    EXECUTE FUNCTION update_mensajes_chat_timestamp();

-- Comentarios en la tabla
COMMENT ON TABLE mensajes_chat IS 'Mensajes de chat entre conductores y clientes durante viajes';
COMMENT ON COLUMN mensajes_chat.solicitud_id IS 'ID de la solicitud/viaje asociada';
COMMENT ON COLUMN mensajes_chat.tipo_remitente IS 'Tipo de usuario que envía: cliente o conductor';
COMMENT ON COLUMN mensajes_chat.tipo_mensaje IS 'Tipo de contenido: texto, imagen, ubicacion, audio, sistema';
COMMENT ON COLUMN mensajes_chat.leido IS 'Si el mensaje ha sido leído por el destinatario';
