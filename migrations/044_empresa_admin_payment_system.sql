-- =====================================================
-- MIGRACIÓN 044: Sistema de Pagos Empresa → Administrador
-- =====================================================
-- Descripción:
-- 1) Tabla de reportes de comprobantes de pago empresa→admin
-- 2) Tabla de facturas permanentes (nunca se eliminan por ley)
-- 3) Configuración de cuenta bancaria del administrador
-- 4) Alertas de deuda para empresas (quincenal)
-- 5) Tipos de notificación para flujo empresa→admin
-- =====================================================

-- ─── Configuración de cuenta bancaria del administrador ───
CREATE TABLE IF NOT EXISTS admin_configuracion_banco (
    id BIGSERIAL PRIMARY KEY,
    admin_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    banco_codigo VARCHAR(40),
    banco_nombre VARCHAR(120),
    tipo_cuenta VARCHAR(20),
    numero_cuenta VARCHAR(60),
    titular_cuenta VARCHAR(150),
    documento_titular VARCHAR(40),
    referencia_transferencia TEXT,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(admin_id)
);

-- ─── Reportes de comprobantes de pago empresa→admin ───
CREATE TABLE IF NOT EXISTS pagos_empresa_reportes (
    id BIGSERIAL PRIMARY KEY,
    empresa_id BIGINT NOT NULL REFERENCES empresas_transporte(id) ON DELETE CASCADE,
    monto_reportado DECIMAL(12,2) NOT NULL CHECK (monto_reportado > 0),
    estado VARCHAR(30) NOT NULL DEFAULT 'pendiente_revision' CHECK (
        estado IN ('pendiente_revision', 'comprobante_aprobado', 'rechazado', 'pagado_confirmado')
    ),
    comprobante_ruta TEXT NOT NULL,
    banco_destino_nombre VARCHAR(120),
    numero_cuenta_destino VARCHAR(60),
    tipo_cuenta_destino VARCHAR(20),
    observaciones_empresa TEXT,
    motivo_rechazo TEXT,
    -- Rastreo de quién aprueba/rechaza/confirma
    aprobado_por BIGINT REFERENCES usuarios(id),
    aprobado_en TIMESTAMP,
    rechazado_por BIGINT REFERENCES usuarios(id),
    rechazado_en TIMESTAMP,
    confirmado_por BIGINT REFERENCES usuarios(id),
    confirmado_en TIMESTAMP,
    -- Referencia al pago registrado final
    pago_empresa_id BIGINT REFERENCES pagos_empresas(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_per_empresa_estado ON pagos_empresa_reportes(empresa_id, estado, created_at DESC);

-- ─── Facturas permanentes (nunca se eliminan) ───
CREATE TABLE IF NOT EXISTS facturas (
    id BIGSERIAL PRIMARY KEY,
    -- Número de factura único y secuencial
    numero_factura VARCHAR(30) NOT NULL UNIQUE,
    -- Tipo: empresa_admin (empresa paga a admin) | conductor_empresa (conductor paga a empresa)
    tipo VARCHAR(30) NOT NULL CHECK (tipo IN ('empresa_admin', 'conductor_empresa')),
    -- Emisor y receptor
    emisor_id BIGINT NOT NULL,
    emisor_tipo VARCHAR(20) NOT NULL CHECK (emisor_tipo IN ('admin', 'empresa')),
    emisor_nombre VARCHAR(200) NOT NULL,
    emisor_documento VARCHAR(40),
    emisor_email VARCHAR(200),
    receptor_id BIGINT NOT NULL,
    receptor_tipo VARCHAR(20) NOT NULL CHECK (receptor_tipo IN ('empresa', 'conductor')),
    receptor_nombre VARCHAR(200) NOT NULL,
    receptor_documento VARCHAR(40),
    receptor_email VARCHAR(200),
    -- Datos financieros
    subtotal DECIMAL(12,2) NOT NULL,
    porcentaje_comision DECIMAL(5,2) DEFAULT 0,
    valor_comision DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) NOT NULL,
    moneda VARCHAR(10) DEFAULT 'COP',
    -- Referencia al pago
    pago_referencia_id BIGINT,
    pago_referencia_tipo VARCHAR(30),
    reporte_id BIGINT,
    -- Archivo PDF en R2
    pdf_ruta TEXT,
    -- Metadatos
    concepto TEXT NOT NULL,
    notas TEXT,
    -- Fechas inmutables
    fecha_emision TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_pago TIMESTAMP,
    -- Estado de la factura
    estado VARCHAR(20) NOT NULL DEFAULT 'emitida' CHECK (estado IN ('emitida', 'pagada', 'anulada')),
    -- Auditoría
    creado_por BIGINT REFERENCES usuarios(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_facturas_tipo ON facturas(tipo, fecha_emision DESC);
CREATE INDEX IF NOT EXISTS idx_facturas_emisor ON facturas(emisor_id, emisor_tipo);
CREATE INDEX IF NOT EXISTS idx_facturas_receptor ON facturas(receptor_id, receptor_tipo);
CREATE INDEX IF NOT EXISTS idx_facturas_numero ON facturas(numero_factura);

-- ─── Alertas de deuda para empresas (quincenal) ───
CREATE TABLE IF NOT EXISTS empresa_alertas_deuda (
    id BIGSERIAL PRIMARY KEY,
    empresa_id BIGINT NOT NULL REFERENCES empresas_transporte(id) ON DELETE CASCADE,
    periodo_clave VARCHAR(30) NOT NULL,
    tipo_alerta VARCHAR(30) NOT NULL CHECK (tipo_alerta IN ('recordatorio', 'obligatoria')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (empresa_id, periodo_clave, tipo_alerta)
);

-- ─── Tipos de notificación para el flujo empresa→admin ───
INSERT INTO tipos_notificacion (codigo, nombre, descripcion, icono, color)
VALUES
    ('empresa_payment_submitted', 'Comprobante de empresa enviado', 'La empresa envió comprobante de pago a la plataforma', 'upload_file', '#2196F3'),
    ('empresa_payment_approved', 'Comprobante de empresa aprobado', 'El administrador aprobó el comprobante de la empresa', 'check_circle', '#4CAF50'),
    ('empresa_payment_rejected', 'Comprobante de empresa rechazado', 'El administrador rechazó el comprobante de la empresa', 'cancel', '#F44336'),
    ('empresa_payment_confirmed', 'Pago de empresa confirmado', 'El administrador confirmó el pago de la empresa', 'payments', '#4CAF50'),
    ('invoice_generated', 'Factura generada', 'Se generó una nueva factura', 'receipt_long', '#1976D2'),
    ('empresa_debt_reminder', 'Recordatorio de deuda empresarial', 'Recordatorio quincenal de pago de deuda con la plataforma', 'schedule', '#FF9800')
ON CONFLICT (codigo) DO NOTHING;
