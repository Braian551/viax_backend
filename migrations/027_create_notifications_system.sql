-- =====================================================
-- MIGRACIÓN 027: Sistema de Notificaciones
-- Fecha: 2026-01-02
-- Descripción: Crea las tablas normalizadas para el
-- sistema de notificaciones de usuarios
-- =====================================================

-- =====================================================
-- TABLA: tipos_notificacion (Catálogo de tipos)
-- Normalización: Evita repetir strings de tipo
-- =====================================================
CREATE TABLE IF NOT EXISTS tipos_notificacion (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    icono VARCHAR(50) DEFAULT 'notifications',
    color VARCHAR(20) DEFAULT '#2196F3',
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insertar tipos de notificación predefinidos
INSERT INTO tipos_notificacion (codigo, nombre, descripcion, icono, color) VALUES
    ('trip_accepted', 'Viaje Aceptado', 'Un conductor ha aceptado tu solicitud de viaje', 'directions_car', '#4CAF50'),
    ('trip_cancelled', 'Viaje Cancelado', 'Tu viaje ha sido cancelado', 'cancel', '#F44336'),
    ('trip_completed', 'Viaje Completado', 'Tu viaje ha finalizado exitosamente', 'check_circle', '#4CAF50'),
    ('driver_arrived', 'Conductor en Camino', 'El conductor está llegando a tu ubicación', 'near_me', '#2196F3'),
    ('driver_waiting', 'Conductor Esperando', 'El conductor te está esperando', 'access_time', '#FF9800'),
    ('payment_received', 'Pago Recibido', 'Tu pago ha sido procesado correctamente', 'payment', '#4CAF50'),
    ('payment_pending', 'Pago Pendiente', 'Tienes un pago pendiente por confirmar', 'pending', '#FF9800'),
    ('promo', 'Promoción', 'Nueva promoción disponible para ti', 'local_offer', '#9C27B0'),
    ('system', 'Sistema', 'Notificación del sistema', 'info', '#607D8B'),
    ('rating_received', 'Calificación Recibida', 'Has recibido una nueva calificación', 'star', '#FFC107'),
    ('chat_message', 'Mensaje Nuevo', 'Tienes un nuevo mensaje', 'chat', '#2196F3'),
    ('dispute_update', 'Actualización de Disputa', 'Hay una actualización en tu disputa', 'gavel', '#FF5722')
ON CONFLICT (codigo) DO NOTHING;

-- =====================================================
-- TABLA: notificaciones_usuario (Notificaciones)
-- Índices optimizados para consultas rápidas
-- =====================================================
CREATE TABLE IF NOT EXISTS notificaciones_usuario (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    tipo_id INTEGER NOT NULL REFERENCES tipos_notificacion(id),
    titulo VARCHAR(255) NOT NULL,
    mensaje TEXT NOT NULL,
    -- Referencias opcionales a entidades relacionadas
    referencia_tipo VARCHAR(50), -- 'viaje', 'pago', 'disputa', etc.
    referencia_id INTEGER,       -- ID de la entidad relacionada
    -- Datos adicionales en JSON para flexibilidad
    data JSONB DEFAULT '{}',
    -- Estado de lectura
    leida BOOLEAN DEFAULT FALSE,
    leida_en TIMESTAMP,
    -- Notificación push enviada
    push_enviada BOOLEAN DEFAULT FALSE,
    push_enviada_en TIMESTAMP,
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Soft delete
    eliminada BOOLEAN DEFAULT FALSE,
    eliminada_en TIMESTAMP
);

-- =====================================================
-- ÍNDICES OPTIMIZADOS para consultas frecuentes
-- =====================================================

-- Índice principal: notificaciones de usuario ordenadas por fecha
CREATE INDEX IF NOT EXISTS idx_notif_usuario_fecha 
    ON notificaciones_usuario(usuario_id, created_at DESC) 
    WHERE eliminada = FALSE;

-- Índice para contar no leídas rápidamente
CREATE INDEX IF NOT EXISTS idx_notif_usuario_no_leidas 
    ON notificaciones_usuario(usuario_id, leida) 
    WHERE eliminada = FALSE AND leida = FALSE;

-- Índice para búsqueda por tipo
CREATE INDEX IF NOT EXISTS idx_notif_tipo 
    ON notificaciones_usuario(tipo_id);

-- Índice para búsqueda por referencia
CREATE INDEX IF NOT EXISTS idx_notif_referencia 
    ON notificaciones_usuario(referencia_tipo, referencia_id) 
    WHERE referencia_id IS NOT NULL;

-- Índice para limpiar notificaciones antiguas
CREATE INDEX IF NOT EXISTS idx_notif_created_at 
    ON notificaciones_usuario(created_at);

-- =====================================================
-- TABLA: configuracion_notificaciones_usuario
-- Preferencias de notificación por usuario
-- =====================================================
CREATE TABLE IF NOT EXISTS configuracion_notificaciones_usuario (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER NOT NULL UNIQUE,
    -- Tipos de notificación habilitados
    push_enabled BOOLEAN DEFAULT TRUE,
    email_enabled BOOLEAN DEFAULT TRUE,
    sms_enabled BOOLEAN DEFAULT FALSE,
    -- Notificaciones específicas
    notif_viajes BOOLEAN DEFAULT TRUE,
    notif_pagos BOOLEAN DEFAULT TRUE,
    notif_promociones BOOLEAN DEFAULT TRUE,
    notif_sistema BOOLEAN DEFAULT TRUE,
    notif_chat BOOLEAN DEFAULT TRUE,
    -- Horario silencioso (opcional)
    horario_silencioso_inicio TIME,
    horario_silencioso_fin TIME,
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Índice para configuración de usuario
CREATE INDEX IF NOT EXISTS idx_config_notif_usuario 
    ON configuracion_notificaciones_usuario(usuario_id);

-- =====================================================
-- TABLA: tokens_push_usuario
-- Tokens FCM/APNs para notificaciones push
-- =====================================================
CREATE TABLE IF NOT EXISTS tokens_push_usuario (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    token TEXT NOT NULL,
    plataforma VARCHAR(20) NOT NULL, -- 'android', 'ios', 'web'
    device_id VARCHAR(255),
    device_name VARCHAR(255),
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Evitar duplicados
    UNIQUE(usuario_id, token)
);

-- Índice para tokens activos de usuario
CREATE INDEX IF NOT EXISTS idx_tokens_push_usuario_activo 
    ON tokens_push_usuario(usuario_id) 
    WHERE activo = TRUE;

-- =====================================================
-- VISTA: notificaciones_completas
-- Vista optimizada para obtener notificaciones con tipo
-- =====================================================
CREATE OR REPLACE VIEW notificaciones_completas AS
SELECT 
    n.id,
    n.usuario_id,
    n.titulo,
    n.mensaje,
    n.leida,
    n.leida_en,
    n.referencia_tipo,
    n.referencia_id,
    n.data,
    n.created_at,
    t.codigo as tipo_codigo,
    t.nombre as tipo_nombre,
    t.icono as tipo_icono,
    t.color as tipo_color
FROM notificaciones_usuario n
INNER JOIN tipos_notificacion t ON n.tipo_id = t.id
WHERE n.eliminada = FALSE;

-- =====================================================
-- FUNCIÓN: crear_notificacion
-- Función helper para crear notificaciones fácilmente
-- =====================================================
CREATE OR REPLACE FUNCTION crear_notificacion(
    p_usuario_id INTEGER,
    p_tipo_codigo VARCHAR(50),
    p_titulo VARCHAR(255),
    p_mensaje TEXT,
    p_referencia_tipo VARCHAR(50) DEFAULT NULL,
    p_referencia_id INTEGER DEFAULT NULL,
    p_data JSONB DEFAULT '{}'
) RETURNS INTEGER AS $$
DECLARE
    v_tipo_id INTEGER;
    v_notif_id INTEGER;
BEGIN
    -- Obtener el ID del tipo
    SELECT id INTO v_tipo_id 
    FROM tipos_notificacion 
    WHERE codigo = p_tipo_codigo AND activo = TRUE;
    
    IF v_tipo_id IS NULL THEN
        -- Usar tipo 'system' como fallback
        SELECT id INTO v_tipo_id 
        FROM tipos_notificacion 
        WHERE codigo = 'system';
    END IF;
    
    -- Insertar la notificación
    INSERT INTO notificaciones_usuario (
        usuario_id, tipo_id, titulo, mensaje, 
        referencia_tipo, referencia_id, data
    ) VALUES (
        p_usuario_id, v_tipo_id, p_titulo, p_mensaje,
        p_referencia_tipo, p_referencia_id, p_data
    ) RETURNING id INTO v_notif_id;
    
    RETURN v_notif_id;
END;
$$ LANGUAGE plpgsql;

-- =====================================================
-- FUNCIÓN: contar_notificaciones_no_leidas
-- Función optimizada para contador de badge
-- =====================================================
CREATE OR REPLACE FUNCTION contar_notificaciones_no_leidas(p_usuario_id INTEGER)
RETURNS INTEGER AS $$
BEGIN
    RETURN (
        SELECT COUNT(*)::INTEGER 
        FROM notificaciones_usuario 
        WHERE usuario_id = p_usuario_id 
          AND leida = FALSE 
          AND eliminada = FALSE
    );
END;
$$ LANGUAGE plpgsql;

-- =====================================================
-- TRIGGER: actualizar_updated_at
-- Actualiza timestamp en configuración
-- =====================================================
CREATE OR REPLACE FUNCTION update_config_notif_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_update_config_notif_timestamp ON configuracion_notificaciones_usuario;
CREATE TRIGGER trigger_update_config_notif_timestamp
    BEFORE UPDATE ON configuracion_notificaciones_usuario
    FOR EACH ROW
    EXECUTE FUNCTION update_config_notif_timestamp();

-- =====================================================
-- COMENTARIOS DE DOCUMENTACIÓN
-- =====================================================
COMMENT ON TABLE tipos_notificacion IS 'Catálogo de tipos de notificación para normalización';
COMMENT ON TABLE notificaciones_usuario IS 'Notificaciones enviadas a usuarios';
COMMENT ON TABLE configuracion_notificaciones_usuario IS 'Preferencias de notificación por usuario';
COMMENT ON TABLE tokens_push_usuario IS 'Tokens FCM/APNs para notificaciones push';
COMMENT ON VIEW notificaciones_completas IS 'Vista optimizada de notificaciones con información de tipo';
COMMENT ON FUNCTION crear_notificacion IS 'Función helper para crear notificaciones de forma sencilla';
COMMENT ON FUNCTION contar_notificaciones_no_leidas IS 'Cuenta notificaciones no leídas de un usuario';
