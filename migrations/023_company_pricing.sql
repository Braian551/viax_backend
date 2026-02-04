-- Migración: 023 Agregar soporte para precios por empresa
-- Fecha: 2025-12-27
-- Descripción: Permite que las empresas configuren sus propios precios

-- 1. Agregar columna empresa_id
ALTER TABLE configuracion_precios 
ADD COLUMN IF NOT EXISTS empresa_id BIGINT NULL;

-- 2. Agregar Foreign Key
ALTER TABLE configuracion_precios
DROP CONSTRAINT IF EXISTS fk_precios_empresa;

ALTER TABLE configuracion_precios
ADD CONSTRAINT fk_precios_empresa
FOREIGN KEY (empresa_id) 
REFERENCES empresas_transporte(id)
ON DELETE CASCADE;

-- 3. Manejar restricciones de Unicidad
-- Eliminamos el constraint simple de tipo_vehiculo si existe (generalmente creado por índice único)
DROP INDEX IF EXISTS idx_tipo_vehiculo_unique;
-- O si es un constraint
ALTER TABLE configuracion_precios DROP CONSTRAINT IF EXISTS tipo_vehiculo_unique;


-- Crear Índices Parciales Únicos
-- 3a. Para configuración global (Admin/Independientes): empresa_id IS NULL
CREATE UNIQUE INDEX IF NOT EXISTS idx_precios_global_unique 
ON configuracion_precios (tipo_vehiculo) 
WHERE empresa_id IS NULL;

-- 3b. Para configuración de empresa: empresa_id IS NOT NULL
CREATE UNIQUE INDEX IF NOT EXISTS idx_precios_empresa_unique 
ON configuracion_precios (empresa_id, tipo_vehiculo) 
WHERE empresa_id IS NOT NULL;

-- Verificar columnas
SELECT column_name, data_type 
FROM information_schema.columns 
WHERE table_name = 'configuracion_precios';
