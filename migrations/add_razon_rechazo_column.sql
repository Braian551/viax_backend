-- Migration: Add razon_rechazo column to detalles_conductor
-- This column stores the reason for document rejection

ALTER TABLE detalles_conductor
ADD COLUMN IF NOT EXISTS razon_rechazo TEXT;

COMMENT ON COLUMN detalles_conductor.razon_rechazo IS 'Razón por la cual los documentos fueron rechazados';
