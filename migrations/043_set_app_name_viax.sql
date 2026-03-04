-- =====================================================
-- MIGRACIÓN 043: Normalizar nombre de app a VIAX
-- Fecha: 2026-03-03
-- =====================================================

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name = 'configuraciones_app'
    ) THEN
        UPDATE configuraciones_app
        SET valor = 'VIAX'
        WHERE clave = 'app_nombre';
    END IF;
END
$$;
