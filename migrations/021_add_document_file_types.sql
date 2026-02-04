-- =====================================================
-- Migración 021: Soporte para PDF y mejoras en documentos
-- Fecha: 2026-01-01
-- Descripción: Agrega campos para tipo de archivo (imagen/pdf)
--              y mejora el sistema de documentos del conductor
-- =====================================================

-- 1. Agregar columna tipo_archivo a detalles_conductor para cada documento
-- Esto permite saber si el archivo es imagen o PDF
ALTER TABLE detalles_conductor
ADD COLUMN IF NOT EXISTS licencia_tipo_archivo VARCHAR(10) DEFAULT 'imagen',
ADD COLUMN IF NOT EXISTS soat_tipo_archivo VARCHAR(10) DEFAULT 'imagen',
ADD COLUMN IF NOT EXISTS tecnomecanica_tipo_archivo VARCHAR(10) DEFAULT 'imagen',
ADD COLUMN IF NOT EXISTS tarjeta_propiedad_tipo_archivo VARCHAR(10) DEFAULT 'imagen',
ADD COLUMN IF NOT EXISTS seguro_tipo_archivo VARCHAR(10) DEFAULT 'imagen';

-- 2. Agregar columna tipo_archivo al historial de documentos
ALTER TABLE documentos_conductor_historial
ADD COLUMN IF NOT EXISTS tipo_archivo VARCHAR(10) DEFAULT 'imagen',
ADD COLUMN IF NOT EXISTS nombre_archivo_original VARCHAR(255),
ADD COLUMN IF NOT EXISTS tamanio_archivo INTEGER;

-- 3. Asegurar que existan todos los campos de seguro necesarios
-- (algunos pueden ya existir, IF NOT EXISTS evita errores)
DO $$ 
BEGIN
    -- Verificar/agregar campos que podrían faltar
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'detalles_conductor' AND column_name = 'aseguradora') THEN
        ALTER TABLE detalles_conductor ADD COLUMN aseguradora VARCHAR(100);
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'detalles_conductor' AND column_name = 'numero_poliza_seguro') THEN
        ALTER TABLE detalles_conductor ADD COLUMN numero_poliza_seguro VARCHAR(50);
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'detalles_conductor' AND column_name = 'vencimiento_seguro') THEN
        ALTER TABLE detalles_conductor ADD COLUMN vencimiento_seguro DATE;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'detalles_conductor' AND column_name = 'seguro_foto_url') THEN
        ALTER TABLE detalles_conductor ADD COLUMN seguro_foto_url VARCHAR(500);
    END IF;
END $$;

-- 4. Comentarios para documentación
COMMENT ON COLUMN detalles_conductor.licencia_tipo_archivo IS 'Tipo de archivo: imagen o pdf';
COMMENT ON COLUMN detalles_conductor.soat_tipo_archivo IS 'Tipo de archivo: imagen o pdf';
COMMENT ON COLUMN detalles_conductor.tecnomecanica_tipo_archivo IS 'Tipo de archivo: imagen o pdf';
COMMENT ON COLUMN detalles_conductor.tarjeta_propiedad_tipo_archivo IS 'Tipo de archivo: imagen o pdf';
COMMENT ON COLUMN detalles_conductor.seguro_tipo_archivo IS 'Tipo de archivo: imagen o pdf';

-- 5. Índice para búsquedas por tipo de archivo (opcional, para reportes)
CREATE INDEX IF NOT EXISTS idx_documentos_historial_tipo 
ON documentos_conductor_historial(tipo_documento, tipo_archivo);
