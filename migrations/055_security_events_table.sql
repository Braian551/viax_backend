-- =============================================
-- Migración: Tabla de eventos de seguridad para monitoreo anti-fraude
-- Almacena alertas de: dispositivos root, HMAC inválido, multi-cuenta, rate limit
-- =============================================

CREATE TABLE IF NOT EXISTS security_events (
    id SERIAL PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    event_data JSONB DEFAULT '{}',
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT NOW()
);

-- Índices para consultas rápidas de investigación
CREATE INDEX IF NOT EXISTS idx_security_events_type ON security_events(event_type);
CREATE INDEX IF NOT EXISTS idx_security_events_created ON security_events(created_at);
CREATE INDEX IF NOT EXISTS idx_security_events_ip ON security_events(ip_address);
