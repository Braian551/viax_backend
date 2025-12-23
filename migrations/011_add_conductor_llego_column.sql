-- Migration: Add conductor_llego_en column to solicitudes_servicio
-- This column tracks when the driver arrived at the pickup point

-- For PostgreSQL
ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS conductor_llego_en TIMESTAMP NULL;

-- Add index for better query performance
CREATE INDEX IF NOT EXISTS idx_solicitudes_conductor_llego ON solicitudes_servicio(conductor_llego_en);

-- For MySQL/MariaDB (alternative syntax)
-- ALTER TABLE solicitudes_servicio 
-- ADD COLUMN conductor_llego_en DATETIME NULL AFTER aceptado_en;
