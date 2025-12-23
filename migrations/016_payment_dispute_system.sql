-- =====================================================
-- Migración 016: Sistema de Disputas de Pago
-- =====================================================
-- Este sistema maneja:
-- 1. Confirmación de pago por cliente
-- 2. Confirmación de recibo por conductor
-- 3. Disputas cuando hay desacuerdo
-- 4. Penalización de usuarios en disputa
-- =====================================================

-- Tabla de disputas de pago
CREATE TABLE IF NOT EXISTS disputas_pago (
    id BIGSERIAL PRIMARY KEY,
    solicitud_id BIGINT NOT NULL REFERENCES solicitudes_servicio(id),
    cliente_id BIGINT NOT NULL REFERENCES usuarios(id),
    conductor_id BIGINT NOT NULL REFERENCES usuarios(id),
    
    -- Estados del pago según cada parte
    cliente_confirma_pago BOOLEAN DEFAULT FALSE,
    conductor_confirma_recibo BOOLEAN DEFAULT FALSE,
    
    -- Estado de la disputa: 'pendiente', 'activa', 'resuelta_cliente', 'resuelta_conductor', 'resuelta_ambos'
    estado VARCHAR(50) DEFAULT 'pendiente',
    
    -- Resolución
    resuelto_por BIGINT REFERENCES usuarios(id),
    resolucion_notas TEXT,
    
    -- Timestamps
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resuelto_en TIMESTAMP,
    
    UNIQUE(solicitud_id)
);

-- Agregar columnas a solicitudes_servicio si no existen
DO $$
BEGIN
    -- Cliente confirmó pago
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'solicitudes_servicio' 
                   AND column_name = 'cliente_confirma_pago') THEN
        ALTER TABLE solicitudes_servicio 
        ADD COLUMN cliente_confirma_pago BOOLEAN DEFAULT FALSE;
    END IF;
    
    -- Conductor confirmó recibo
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'solicitudes_servicio' 
                   AND column_name = 'conductor_confirma_recibo') THEN
        ALTER TABLE solicitudes_servicio 
        ADD COLUMN conductor_confirma_recibo BOOLEAN DEFAULT FALSE;
    END IF;
    
    -- Tiene disputa activa
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'solicitudes_servicio' 
                   AND column_name = 'tiene_disputa') THEN
        ALTER TABLE solicitudes_servicio 
        ADD COLUMN tiene_disputa BOOLEAN DEFAULT FALSE;
    END IF;
    
    -- ID de la disputa
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'solicitudes_servicio' 
                   AND column_name = 'disputa_id') THEN
        ALTER TABLE solicitudes_servicio 
        ADD COLUMN disputa_id BIGINT REFERENCES disputas_pago(id);
    END IF;
END $$;

-- Agregar columna de penalización a usuarios
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' 
                   AND column_name = 'tiene_disputa_activa') THEN
        ALTER TABLE usuarios 
        ADD COLUMN tiene_disputa_activa BOOLEAN DEFAULT FALSE;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' 
                   AND column_name = 'disputa_activa_id') THEN
        ALTER TABLE usuarios 
        ADD COLUMN disputa_activa_id BIGINT REFERENCES disputas_pago(id);
    END IF;
END $$;

-- Índices
CREATE INDEX IF NOT EXISTS idx_disputas_solicitud ON disputas_pago(solicitud_id);
CREATE INDEX IF NOT EXISTS idx_disputas_cliente ON disputas_pago(cliente_id);
CREATE INDEX IF NOT EXISTS idx_disputas_conductor ON disputas_pago(conductor_id);
CREATE INDEX IF NOT EXISTS idx_disputas_estado ON disputas_pago(estado);
CREATE INDEX IF NOT EXISTS idx_usuarios_disputa ON usuarios(tiene_disputa_activa) WHERE tiene_disputa_activa = TRUE;

-- Comentarios
COMMENT ON TABLE disputas_pago IS 'Registro de disputas de pago entre cliente y conductor';
COMMENT ON COLUMN disputas_pago.estado IS 'pendiente=esperando confirmaciones, activa=hay desacuerdo, resuelta_*=ya resuelto';
