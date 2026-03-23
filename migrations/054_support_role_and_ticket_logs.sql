-- =====================================================
-- MIGRACION 054: Rol soporte tecnico y trazabilidad tickets
-- Fecha: 2026-03-15
-- =====================================================

-- 1) Habilitar nuevo rol para usuarios de soporte tecnico.
ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_tipo_usuario_check;
ALTER TABLE usuarios
    ADD CONSTRAINT usuarios_tipo_usuario_check
    CHECK (tipo_usuario IN ('cliente', 'conductor', 'administrador', 'empresa', 'soporte_tecnico'));

-- 2) Trazabilidad de acciones operativas sobre tickets.
CREATE TABLE IF NOT EXISTS ticket_soporte_logs (
    id BIGSERIAL PRIMARY KEY,
    ticket_id INTEGER NOT NULL REFERENCES tickets_soporte(id) ON DELETE CASCADE,
    actor_id INTEGER,
    accion VARCHAR(60) NOT NULL,
    estado_anterior VARCHAR(30),
    estado_nuevo VARCHAR(30),
    prioridad_anterior VARCHAR(20),
    prioridad_nueva VARCHAR(20),
    metadata JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ticket_soporte_logs_ticket_id ON ticket_soporte_logs(ticket_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ticket_soporte_logs_actor_id ON ticket_soporte_logs(actor_id, created_at DESC);

COMMENT ON TABLE ticket_soporte_logs IS 'Registro historico de cambios en tickets de soporte';
