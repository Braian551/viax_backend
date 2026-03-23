-- =====================================================
-- MIGRACIÓN 062: Seguridad de datos financieros sensibles
-- =====================================================
-- 1) Auditoría de acceso a números de cuenta
-- 2) Ampliación de columnas para soportar payload cifrado (AES + IV + metadata)

CREATE TABLE IF NOT EXISTS financial_access_audit_logs (
    id BIGSERIAL PRIMARY KEY,
    actor_user_id BIGINT,
    actor_role VARCHAR(40) NOT NULL,
    resource_type VARCHAR(40) NOT NULL,
    resource_id BIGINT,
    granted BOOLEAN NOT NULL DEFAULT FALSE,
    reason VARCHAR(120) NOT NULL,
    ip_address VARCHAR(64),
    user_agent VARCHAR(255),
    accessed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_financial_audit_actor_time
    ON financial_access_audit_logs(actor_user_id, accessed_at DESC);

CREATE INDEX IF NOT EXISTS idx_financial_audit_resource_time
    ON financial_access_audit_logs(resource_type, resource_id, accessed_at DESC);

ALTER TABLE admin_configuracion_banco
    ALTER COLUMN numero_cuenta TYPE VARCHAR(255);

ALTER TABLE empresas_configuracion
    ALTER COLUMN numero_cuenta TYPE VARCHAR(255);

ALTER TABLE pagos_comision_reportes
    ALTER COLUMN numero_cuenta_destino TYPE VARCHAR(255);

ALTER TABLE pagos_empresa_reportes
    ALTER COLUMN numero_cuenta_destino TYPE VARCHAR(255);
