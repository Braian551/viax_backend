-- Sistema de eliminación segura de cuentas (cliente, conductor, empresa)
-- Cumplimiento: retención de 15 días, reactivación y eliminación irreversible.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'usuarios' AND column_name = 'status'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'active';
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'usuarios' AND column_name = 'deletion_requested_at'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN deletion_requested_at TIMESTAMP NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'usuarios' AND column_name = 'deletion_scheduled_at'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN deletion_scheduled_at TIMESTAMP NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'usuarios' AND column_name = 'deleted_at'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN deleted_at TIMESTAMP NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'usuarios' AND column_name = 'deleted_reason'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN deleted_reason VARCHAR(255) NULL;
    END IF;
END $$;

UPDATE usuarios
SET status = 'active'
WHERE status IS NULL OR TRIM(status) = '';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_usuarios_status_account_lifecycle'
    ) THEN
        ALTER TABLE usuarios
        ADD CONSTRAINT chk_usuarios_status_account_lifecycle
        CHECK (status IN ('active', 'pending_deletion', 'deleted'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_usuarios_status ON usuarios(status);
CREATE INDEX IF NOT EXISTS idx_usuarios_deletion_scheduled ON usuarios(deletion_scheduled_at);

CREATE TABLE IF NOT EXISTS account_deletion_codes (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    email VARCHAR(120) NOT NULL,
    code VARCHAR(6) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    used_at TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_account_deletion_codes_lookup
    ON account_deletion_codes(user_id, email, code, used, expires_at);

CREATE INDEX IF NOT EXISTS idx_account_deletion_codes_expiry
    ON account_deletion_codes(expires_at);
