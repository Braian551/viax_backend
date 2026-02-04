-- =====================================================
-- MIGRACIÓN 036: Sistema de Pagos de Comisión
-- =====================================================
-- Descripción: Crea tabla para registrar pagos de comisiones
--              de conductores a la empresa.
-- Fecha: 2026-01-21
-- =====================================================

CREATE TABLE IF NOT EXISTS pagos_comision (
  id SERIAL PRIMARY KEY,
  conductor_id INTEGER NOT NULL,
  monto DECIMAL(10, 2) NOT NULL,
  metodo_pago VARCHAR(50) NOT NULL DEFAULT 'efectivo',
  referencia VARCHAR(255) DEFAULT NULL,
  admin_id INTEGER DEFAULT NULL,
  fecha_pago TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  notas TEXT,
  
  CONSTRAINT fk_pagos_conductor FOREIGN KEY (conductor_id) REFERENCES usuarios (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_conductor_pago ON pagos_comision (conductor_id);
CREATE INDEX IF NOT EXISTS idx_fecha_pago ON pagos_comision (fecha_pago);

