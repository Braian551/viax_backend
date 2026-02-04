-- =====================================================
-- MIGRACIÓN 025: Sistema de Comisiones Empresa-Admin
-- =====================================================
-- Descripción: Agrega campos para gestionar las comisiones
--              que admin cobra a cada empresa de transporte.
--              Cada empresa puede tener un % diferente.
-- Fecha: 2025-12-29
-- =====================================================

-- 1. Agregar columna comision_admin_porcentaje a empresas_transporte
-- Este es el porcentaje de la comisión de la empresa que se lleva el admin
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'empresas_transporte' 
                   AND column_name = 'comision_admin_porcentaje') THEN
        ALTER TABLE empresas_transporte 
        ADD COLUMN comision_admin_porcentaje DECIMAL(5,2) DEFAULT 0;
        
        COMMENT ON COLUMN empresas_transporte.comision_admin_porcentaje IS 
            'Porcentaje de la comision de la empresa que va para admin (0-100)';
    END IF;
END $$;

-- 2. Agregar columna saldo_pendiente para tracking de deudas
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'empresas_transporte' 
                   AND column_name = 'saldo_pendiente') THEN
        ALTER TABLE empresas_transporte 
        ADD COLUMN saldo_pendiente DECIMAL(15,2) DEFAULT 0;
        
        COMMENT ON COLUMN empresas_transporte.saldo_pendiente IS 
            'Saldo que la empresa debe pagar a la plataforma (COP)';
    END IF;
END $$;

-- 3. Crear tabla de historial de pagos de empresas
CREATE TABLE IF NOT EXISTS pagos_empresas (
    id BIGSERIAL PRIMARY KEY,
    empresa_id BIGINT NOT NULL REFERENCES empresas_transporte(id) ON DELETE CASCADE,
    monto DECIMAL(15,2) NOT NULL,
    tipo VARCHAR(50) NOT NULL, -- 'cargo' (por viaje), 'pago' (empresa paga)
    descripcion TEXT,
    viaje_id BIGINT, -- Referencia opcional al viaje
    saldo_anterior DECIMAL(15,2),
    saldo_nuevo DECIMAL(15,2),
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_pagos_empresas_empresa ON pagos_empresas(empresa_id);
CREATE INDEX IF NOT EXISTS idx_pagos_empresas_fecha ON pagos_empresas(creado_en);

COMMENT ON TABLE pagos_empresas IS 'Historial de cargos y pagos de empresas a la plataforma';

-- 4. Verificar columnas añadidas
SELECT column_name, data_type, column_default
FROM information_schema.columns 
WHERE table_name = 'empresas_transporte' 
AND column_name IN ('comision_admin_porcentaje', 'saldo_pendiente');
