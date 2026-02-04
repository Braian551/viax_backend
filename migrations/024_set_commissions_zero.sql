-- =====================================================
-- MIGRACIÓN 024: Establecer Comisiones a Cero
-- =====================================================
-- Descripción: Actualiza las comisiones a 0% ya que por el momento
--              el método de pago es solo efectivo y no se cobrará
--              comisión al conductor.
-- Fecha: 2025-12-29
-- =====================================================

-- Establecer todas las comisiones a 0
UPDATE configuracion_precios 
SET comision_plataforma = 0,
    comision_metodo_pago = 0,
    notas = CONCAT(COALESCE(notas, ''), ' | Comisiones establecidas a 0 - Dic 2025 (pago en efectivo, sin cobro a conductores)'),
    fecha_actualizacion = NOW();

-- Verificar los cambios
SELECT 
    id,
    tipo_vehiculo,
    comision_plataforma,
    comision_metodo_pago,
    fecha_actualizacion
FROM configuracion_precios;
