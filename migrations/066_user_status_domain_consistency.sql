-- 066_user_status_domain_consistency.sql
-- Endurece el dominio de estados de usuarios sin mutar datos existentes.

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'usuarios_status_check'
    ) THEN
        ALTER TABLE usuarios DROP CONSTRAINT usuarios_status_check;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_usuarios_status_domain'
    ) THEN
        ALTER TABLE usuarios
        ADD CONSTRAINT chk_usuarios_status_domain
        CHECK (status IN ('active', 'inactive', 'pending_deletion', 'deleted'))
        NOT VALID;
    END IF;
END $$;

ALTER TABLE usuarios
    ALTER COLUMN status SET DEFAULT 'active';
