-- Migration 019: Add vehicle photo to driver details
-- Date: 2025-12-25

ALTER TABLE detalles_conductor
ADD COLUMN IF NOT EXISTS foto_vehiculo VARCHAR(255) NULL;

-- Log the migration
COMMENT ON COLUMN detalles_conductor.foto_vehiculo IS 'URL relative path to the vehicle photo';
