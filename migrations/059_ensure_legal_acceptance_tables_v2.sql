-- Garantiza tablas legales en entornos legacy donde no quedaron creadas.
-- @allow-data-migration

CREATE TABLE IF NOT EXISTS legal_documents (
    id SERIAL PRIMARY KEY,
    role VARCHAR(50) NOT NULL,
    version VARCHAR(20) NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    is_active BOOLEAN DEFAULT false,
    published_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(role, version)
);

CREATE TABLE IF NOT EXISTS legal_acceptance_logs (
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

CREATE INDEX IF NOT EXISTS idx_legal_acc_user_role
    ON legal_acceptance_logs(user_id, role, accepted_version);

CREATE OR REPLACE FUNCTION prevent_legal_logs_update()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'NO_UPDATE_ALLOWED: Data is immutable for legal auditing.';
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION prevent_legal_logs_delete()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'NO_DELETE_ALLOWED: Data is immutable for legal auditing.';
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgname = 'prevent_update_legal_logs_trigger'
    ) THEN
        CREATE TRIGGER prevent_update_legal_logs_trigger
        BEFORE UPDATE ON legal_acceptance_logs
        FOR EACH ROW EXECUTE FUNCTION prevent_legal_logs_update();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgname = 'prevent_delete_legal_logs_trigger'
    ) THEN
        CREATE TRIGGER prevent_delete_legal_logs_trigger
        BEFORE DELETE ON legal_acceptance_logs
        FOR EACH ROW EXECUTE FUNCTION prevent_legal_logs_delete();
    END IF;
END $$;
