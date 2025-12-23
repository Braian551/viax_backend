-- Migration to add missing columns for vehicle registration
-- Execute this script to add support for complete vehicle and license information

ALTER TABLE `detalles_conductor`
ADD COLUMN IF NOT EXISTS `licencia_expedicion` DATE NULL AFTER `licencia_vencimiento`,
ADD COLUMN IF NOT EXISTS `licencia_categoria` VARCHAR(10) NULL DEFAULT 'C1' AFTER `licencia_expedicion`,
ADD COLUMN IF NOT EXISTS `soat_numero` VARCHAR(50) NULL AFTER `vencimiento_seguro`,
ADD COLUMN IF NOT EXISTS `soat_vencimiento` DATE NULL AFTER `soat_numero`,
ADD COLUMN IF NOT EXISTS `tecnomecanica_numero` VARCHAR(50) NULL AFTER `soat_vencimiento`,
ADD COLUMN IF NOT EXISTS `tecnomecanica_vencimiento` DATE NULL AFTER `tecnomecanica_numero`,
ADD COLUMN IF NOT EXISTS `tarjeta_propiedad_numero` VARCHAR(50) NULL AFTER `tecnomecanica_vencimiento`;

-- Add indexes for better performance
ALTER TABLE `detalles_conductor`
ADD INDEX IF NOT EXISTS `idx_licencia_vencimiento` (`licencia_vencimiento`),
ADD INDEX IF NOT EXISTS `idx_soat_vencimiento` (`soat_vencimiento`),
ADD INDEX IF NOT EXISTS `idx_tecnomecanica_vencimiento` (`tecnomecanica_vencimiento`);

-- Show structure to verify
DESCRIBE `detalles_conductor`;
