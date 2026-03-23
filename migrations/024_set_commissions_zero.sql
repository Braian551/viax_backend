-- =====================================================
-- MIGRACIÓN 024: DESACTIVADA POR SEGURIDAD DE DATOS
-- =====================================================
-- Esta migración alteraba comisiones actuales (UPDATE masivo) y podía
-- dañar datos productivos si se re-ejecutaba en despliegues.
--
-- Política vigente:
-- 1) Ninguna migración debe mutar datos actuales por defecto.
-- 2) Cambios de datos solo mediante scripts controlados/manuales.
-- =====================================================

DO $$
BEGIN
    RAISE NOTICE '024_set_commissions_zero.sql desactivada por seguridad: no se aplican cambios de datos.';
END
$$;
