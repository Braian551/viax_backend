-- =====================================================
-- MIGRACION 055: Sistema de Aceptacion Legal Anti-Bypass
-- =====================================================

CREATE TABLE legal_documents (
    id SERIAL PRIMARY KEY,
    role VARCHAR(50) NOT NULL,
    version VARCHAR(20) NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    is_active BOOLEAN DEFAULT false,
    published_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(role, version)
);

CREATE TABLE legal_acceptance_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    role VARCHAR(50) NOT NULL,
    accepted_version VARCHAR(20) NOT NULL,
    document_hash VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    device_id VARCHAR(255) NOT NULL,
    acceptance_hash VARCHAR(64) NOT NULL,
    accepted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_legal_acc_user_role ON legal_acceptance_logs(user_id, role, accepted_version);

-- -----------------------------------------------------
-- Funciones Trigger para Forzar Inmutabilidad
-- -----------------------------------------------------

CREATE OR REPLACE FUNCTION prevent_legal_logs_update()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'NO_UPDATE_ALLOWED: Data is immutable for legal auditing.';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER prevent_update_legal_logs_trigger
BEFORE UPDATE ON legal_acceptance_logs
FOR EACH ROW EXECUTE FUNCTION prevent_legal_logs_update();

CREATE OR REPLACE FUNCTION prevent_legal_logs_delete()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'NO_DELETE_ALLOWED: Data is immutable for legal auditing.';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER prevent_delete_legal_logs_trigger
BEFORE DELETE ON legal_acceptance_logs
FOR EACH ROW EXECUTE FUNCTION prevent_legal_logs_delete();
