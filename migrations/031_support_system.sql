-- =====================================================
-- MIGRACIÓN 031: Sistema de Soporte
-- Fecha: 2026-01-12
-- Descripción: Crea las tablas normalizadas para el
-- sistema de tickets de soporte y callbacks
-- =====================================================

-- =====================================================
-- TABLA: categorias_soporte (Catálogo de categorías)
-- =====================================================
CREATE TABLE IF NOT EXISTS categorias_soporte (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    icono VARCHAR(50) DEFAULT 'support',
    color VARCHAR(20) DEFAULT '#2196F3',
    orden INT DEFAULT 0,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insertar categorías predefinidas
INSERT INTO categorias_soporte (codigo, nombre, descripcion, icono, color, orden) VALUES
    ('viaje', 'Problemas con viajes', 'Incidentes durante el viaje, rutas incorrectas, etc.', 'directions_car', '#4CAF50', 1),
    ('pago', 'Pagos y facturación', 'Problemas con cobros, reembolsos, facturas', 'payment', '#FF9800', 2),
    ('cuenta', 'Mi cuenta', 'Problemas de acceso, actualización de datos', 'person', '#2196F3', 3),
    ('conductor', 'Conductor', 'Reportar comportamiento, quejas o felicitaciones', 'badge', '#9C27B0', 4),
    ('app', 'Problemas técnicos', 'Errores en la aplicación, fallas técnicas', 'bug_report', '#F44336', 5),
    ('seguridad', 'Seguridad', 'Reportar situaciones de seguridad', 'security', '#E91E63', 6),
    ('sugerencia', 'Sugerencias', 'Ideas para mejorar el servicio', 'lightbulb', '#00BCD4', 7),
    ('otro', 'Otro', 'Otras consultas generales', 'help_outline', '#607D8B', 8)
ON CONFLICT (codigo) DO NOTHING;

-- =====================================================
-- TABLA: tickets_soporte
-- =====================================================
CREATE TABLE IF NOT EXISTS tickets_soporte (
    id SERIAL PRIMARY KEY,
    numero_ticket VARCHAR(20) NOT NULL UNIQUE,
    usuario_id INTEGER NOT NULL,
    categoria_id INTEGER NOT NULL REFERENCES categorias_soporte(id),
    asunto VARCHAR(255) NOT NULL,
    descripcion TEXT,
    -- Estado del ticket
    estado VARCHAR(30) DEFAULT 'abierto' CHECK (estado IN ('abierto', 'en_progreso', 'esperando_usuario', 'resuelto', 'cerrado')),
    prioridad VARCHAR(20) DEFAULT 'normal' CHECK (prioridad IN ('baja', 'normal', 'alta', 'urgente')),
    -- Referencias opcionales
    viaje_id INTEGER,
    -- Asignación
    agente_id INTEGER,
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resuelto_en TIMESTAMP,
    cerrado_en TIMESTAMP
);

-- Función para generar número de ticket
CREATE OR REPLACE FUNCTION generar_numero_ticket()
RETURNS TRIGGER AS $$
BEGIN
    NEW.numero_ticket := 'TKT-' || TO_CHAR(NOW(), 'YYYYMMDD') || '-' || LPAD(NEW.id::TEXT, 5, '0');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger para generar número de ticket
DROP TRIGGER IF EXISTS trigger_generar_numero_ticket ON tickets_soporte;
CREATE TRIGGER trigger_generar_numero_ticket
    BEFORE INSERT ON tickets_soporte
    FOR EACH ROW
    WHEN (NEW.numero_ticket IS NULL OR NEW.numero_ticket = '')
    EXECUTE FUNCTION generar_numero_ticket();

-- Índices
CREATE INDEX IF NOT EXISTS idx_tickets_usuario ON tickets_soporte(usuario_id);
CREATE INDEX IF NOT EXISTS idx_tickets_estado ON tickets_soporte(estado);
CREATE INDEX IF NOT EXISTS idx_tickets_categoria ON tickets_soporte(categoria_id);
CREATE INDEX IF NOT EXISTS idx_tickets_created ON tickets_soporte(created_at DESC);

-- =====================================================
-- TABLA: mensajes_ticket
-- =====================================================
CREATE TABLE IF NOT EXISTS mensajes_ticket (
    id SERIAL PRIMARY KEY,
    ticket_id INTEGER NOT NULL REFERENCES tickets_soporte(id) ON DELETE CASCADE,
    remitente_id INTEGER NOT NULL,
    es_agente BOOLEAN DEFAULT FALSE,
    mensaje TEXT NOT NULL,
    -- Archivos adjuntos (JSON array de URLs)
    adjuntos JSONB DEFAULT '[]',
    leido BOOLEAN DEFAULT FALSE,
    leido_en TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Índices
CREATE INDEX IF NOT EXISTS idx_mensajes_ticket ON mensajes_ticket(ticket_id);
CREATE INDEX IF NOT EXISTS idx_mensajes_created ON mensajes_ticket(created_at);

-- =====================================================
-- TABLA: solicitudes_callback
-- =====================================================
CREATE TABLE IF NOT EXISTS solicitudes_callback (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    motivo VARCHAR(255),
    estado VARCHAR(20) DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'programado', 'realizado', 'fallido', 'cancelado')),
    notas TEXT,
    programado_para TIMESTAMP,
    realizado_en TIMESTAMP,
    agente_id INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Índices
CREATE INDEX IF NOT EXISTS idx_callback_usuario ON solicitudes_callback(usuario_id);
CREATE INDEX IF NOT EXISTS idx_callback_estado ON solicitudes_callback(estado);

-- =====================================================
-- TRIGGER: Actualizar updated_at
-- =====================================================
CREATE OR REPLACE FUNCTION update_support_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_update_ticket_timestamp ON tickets_soporte;
CREATE TRIGGER trigger_update_ticket_timestamp
    BEFORE UPDATE ON tickets_soporte
    FOR EACH ROW
    EXECUTE FUNCTION update_support_timestamp();

DROP TRIGGER IF EXISTS trigger_update_callback_timestamp ON solicitudes_callback;
CREATE TRIGGER trigger_update_callback_timestamp
    BEFORE UPDATE ON solicitudes_callback
    FOR EACH ROW
    EXECUTE FUNCTION update_support_timestamp();

-- =====================================================
-- VISTA: tickets_completos
-- =====================================================
CREATE OR REPLACE VIEW tickets_completos AS
SELECT 
    t.id,
    t.numero_ticket,
    t.usuario_id,
    t.asunto,
    t.descripcion,
    t.estado,
    t.prioridad,
    t.viaje_id,
    t.created_at,
    t.updated_at,
    t.resuelto_en,
    c.codigo as categoria_codigo,
    c.nombre as categoria_nombre,
    c.icono as categoria_icono,
    c.color as categoria_color,
    u.nombre as usuario_nombre,
    u.email as usuario_email,
    (SELECT COUNT(*) FROM mensajes_ticket m WHERE m.ticket_id = t.id) as total_mensajes,
    (SELECT COUNT(*) FROM mensajes_ticket m WHERE m.ticket_id = t.id AND m.es_agente = TRUE AND m.leido = FALSE) as mensajes_no_leidos
FROM tickets_soporte t
INNER JOIN categorias_soporte c ON t.categoria_id = c.id
INNER JOIN usuarios u ON t.usuario_id = u.id;

-- =====================================================
-- COMENTARIOS
-- =====================================================
COMMENT ON TABLE categorias_soporte IS 'Catálogo de categorías de soporte';
COMMENT ON TABLE tickets_soporte IS 'Tickets de soporte de usuarios';
COMMENT ON TABLE mensajes_ticket IS 'Mensajes dentro de un ticket de soporte';
COMMENT ON TABLE solicitudes_callback IS 'Solicitudes de llamada de vuelta';
