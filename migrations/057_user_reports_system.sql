-- Sistema de reportes de usuarios (cliente/conductor)
-- @allow-data-migration

CREATE TABLE IF NOT EXISTS reportes_usuarios (
    id BIGSERIAL PRIMARY KEY,
    reporter_user_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    reported_user_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    solicitud_id BIGINT NULL REFERENCES solicitudes_servicio(id) ON DELETE SET NULL,
    motivo VARCHAR(80) NOT NULL,
    descripcion TEXT NULL,
    evidencia_json JSONB NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'pendiente',
    prioridad VARCHAR(20) NOT NULL DEFAULT 'media',
    reviewed_by BIGINT NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    reviewed_at TIMESTAMP NULL,
    resolution_note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_reporte_not_self CHECK (reporter_user_id <> reported_user_id),
    CONSTRAINT chk_reporte_estado CHECK (estado IN ('pendiente', 'en_revision', 'resuelto', 'descartado')),
    CONSTRAINT chk_reporte_prioridad CHECK (prioridad IN ('baja', 'media', 'alta', 'urgente'))
);

ALTER TABLE reportes_usuarios
    ADD COLUMN IF NOT EXISTS reporter_user_id BIGINT NULL REFERENCES usuarios(id) ON DELETE CASCADE;

ALTER TABLE reportes_usuarios
    ADD COLUMN IF NOT EXISTS reported_user_id BIGINT NULL REFERENCES usuarios(id) ON DELETE CASCADE;

ALTER TABLE reportes_usuarios
    ADD COLUMN IF NOT EXISTS motivo VARCHAR(80) NULL;

ALTER TABLE reportes_usuarios
    ADD COLUMN IF NOT EXISTS evidencia_json JSONB NULL;

ALTER TABLE reportes_usuarios
    ADD COLUMN IF NOT EXISTS prioridad VARCHAR(20) NOT NULL DEFAULT 'media';

ALTER TABLE reportes_usuarios
    ADD COLUMN IF NOT EXISTS reviewed_by BIGINT NULL REFERENCES usuarios(id) ON DELETE SET NULL;

ALTER TABLE reportes_usuarios
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW();

ALTER TABLE reportes_usuarios
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW();

ALTER TABLE reportes_usuarios
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP NULL;

ALTER TABLE reportes_usuarios
    ADD COLUMN IF NOT EXISTS resolution_note TEXT NULL;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'reportes_usuarios'
          AND column_name = 'usuario_reportante_id'
    ) THEN
        EXECUTE $SQL$
            UPDATE reportes_usuarios
            SET
                reporter_user_id = COALESCE(reporter_user_id, usuario_reportante_id),
                reported_user_id = COALESCE(reported_user_id, usuario_reportado_id),
                motivo = COALESCE(motivo, tipo_reporte),
                reviewed_by = COALESCE(reviewed_by, admin_revisor_id),
                created_at = COALESCE(created_at, fecha_creacion, NOW()),
                reviewed_at = COALESCE(reviewed_at, fecha_resolucion),
                resolution_note = COALESCE(resolution_note, notas_admin)
            WHERE
                reporter_user_id IS NULL
                OR reported_user_id IS NULL
                OR motivo IS NULL
                OR reviewed_by IS NULL
                OR created_at IS NULL
                OR reviewed_at IS NULL
                OR resolution_note IS NULL
        $SQL$;
    ELSE
        UPDATE reportes_usuarios
        SET
            created_at = COALESCE(created_at, NOW()),
            updated_at = COALESCE(updated_at, NOW())
        WHERE
            created_at IS NULL
            OR updated_at IS NULL;
    END IF;
END $$;

UPDATE reportes_usuarios
SET estado = 'descartado'
WHERE estado NOT IN ('pendiente', 'en_revision', 'resuelto', 'descartado');

UPDATE reportes_usuarios
SET prioridad = 'media'
WHERE prioridad NOT IN ('baja', 'media', 'alta', 'urgente');

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_reporte_not_self'
          AND conrelid = 'reportes_usuarios'::regclass
    ) THEN
        ALTER TABLE reportes_usuarios
            ADD CONSTRAINT chk_reporte_not_self CHECK (reporter_user_id <> reported_user_id);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_reporte_estado'
          AND conrelid = 'reportes_usuarios'::regclass
    ) THEN
        ALTER TABLE reportes_usuarios
            ADD CONSTRAINT chk_reporte_estado CHECK (estado IN ('pendiente', 'en_revision', 'resuelto', 'descartado'));
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_reporte_prioridad'
          AND conrelid = 'reportes_usuarios'::regclass
    ) THEN
        ALTER TABLE reportes_usuarios
            ADD CONSTRAINT chk_reporte_prioridad CHECK (prioridad IN ('baja', 'media', 'alta', 'urgente'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_reportes_usuarios_estado
    ON reportes_usuarios(estado, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_reportes_usuarios_reporter
    ON reportes_usuarios(reporter_user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_reportes_usuarios_reported
    ON reportes_usuarios(reported_user_id, created_at DESC);

CREATE UNIQUE INDEX IF NOT EXISTS uq_reportes_usuarios_pending_trip_pair
    ON reportes_usuarios(reporter_user_id, reported_user_id, COALESCE(solicitud_id, 0), estado)
    WHERE estado IN ('pendiente', 'en_revision');
