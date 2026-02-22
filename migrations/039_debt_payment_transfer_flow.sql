-- =====================================================
-- MIGRACIÓN 039: Flujo de pago de deuda por transferencia
-- =====================================================
-- Descripción:
-- 1) Amplía configuración de empresa con cuenta bancaria
-- 2) Crea reportes de comprobantes de pago de comisión
-- 3) Crea tabla de control de alertas por quincena
-- 4) Registra tipos de notificación para deuda de comisiones
-- =====================================================

ALTER TABLE empresas_configuracion
    ADD COLUMN IF NOT EXISTS banco_codigo VARCHAR(40),
    ADD COLUMN IF NOT EXISTS banco_nombre VARCHAR(120),
    ADD COLUMN IF NOT EXISTS tipo_cuenta VARCHAR(20),
    ADD COLUMN IF NOT EXISTS numero_cuenta VARCHAR(60),
    ADD COLUMN IF NOT EXISTS titular_cuenta VARCHAR(150),
    ADD COLUMN IF NOT EXISTS documento_titular VARCHAR(40),
    ADD COLUMN IF NOT EXISTS referencia_transferencia TEXT,
    ADD COLUMN IF NOT EXISTS actualizado_banco_en TIMESTAMP;

CREATE TABLE IF NOT EXISTS pagos_comision_reportes (
    id BIGSERIAL PRIMARY KEY,
    conductor_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    empresa_id BIGINT NOT NULL REFERENCES empresas_transporte(id) ON DELETE CASCADE,
    monto_reportado DECIMAL(12,2) NOT NULL CHECK (monto_reportado > 0),
    estado VARCHAR(30) NOT NULL DEFAULT 'pendiente_revision' CHECK (
        estado IN ('pendiente_revision', 'comprobante_aprobado', 'rechazado', 'pagado_confirmado')
    ),
    comprobante_ruta TEXT NOT NULL,
    banco_destino_nombre VARCHAR(120),
    numero_cuenta_destino VARCHAR(60),
    tipo_cuenta_destino VARCHAR(20),
    observaciones_conductor TEXT,
    motivo_rechazo TEXT,
    aprobado_por BIGINT REFERENCES usuarios(id),
    aprobado_en TIMESTAMP,
    rechazado_por BIGINT REFERENCES usuarios(id),
    rechazado_en TIMESTAMP,
    confirmado_por BIGINT REFERENCES usuarios(id),
    confirmado_en TIMESTAMP,
    pago_comision_id BIGINT REFERENCES pagos_comision(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_pcr_conductor_estado ON pagos_comision_reportes(conductor_id, estado, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pcr_empresa_estado ON pagos_comision_reportes(empresa_id, estado, created_at DESC);

CREATE TABLE IF NOT EXISTS conductor_alertas_deuda (
    id BIGSERIAL PRIMARY KEY,
    conductor_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    periodo_clave VARCHAR(30) NOT NULL,
    tipo_alerta VARCHAR(30) NOT NULL CHECK (tipo_alerta IN ('recordatorio', 'obligatoria')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (conductor_id, periodo_clave, tipo_alerta)
);

INSERT INTO tipos_notificacion (codigo, nombre, descripcion, icono, color)
VALUES
    ('debt_payment_reminder', 'Recordatorio de pago de deuda', 'Recordatorio quincenal de pago de deuda de comisión', 'schedule', '#FF9800'),
    ('debt_payment_mandatory', 'Pago de deuda obligatorio', 'Recordatorio obligatorio para reportar pago de deuda', 'warning', '#F44336'),
    ('debt_payment_submitted', 'Comprobante enviado', 'El conductor envió comprobante de pago de deuda', 'upload_file', '#2196F3'),
    ('debt_payment_approved', 'Comprobante aprobado', 'La empresa aprobó tu comprobante de deuda', 'check_circle', '#4CAF50'),
    ('debt_payment_rejected', 'Comprobante rechazado', 'La empresa rechazó tu comprobante de deuda', 'cancel', '#F44336'),
    ('debt_payment_confirmed', 'Deuda confirmada como pagada', 'La empresa confirmó el pago final de deuda', 'payments', '#4CAF50')
ON CONFLICT (codigo) DO NOTHING;
