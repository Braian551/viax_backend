-- Migration 004: Fix fecha_creacion columns for solicitudes_servicio and transacciones
-- Problema: El código PHP usa fecha_creacion pero las tablas usan solicitado_en y fecha_transaccion
-- Solución: Agregar columna fecha_creacion como alias/copia o actualizar las referencias

-- Para solicitudes_servicio
ALTER TABLE `solicitudes_servicio` 
ADD COLUMN `fecha_creacion` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER `estado`;

-- Copiar datos existentes de solicitado_en a fecha_creacion
UPDATE `solicitudes_servicio` 
SET `fecha_creacion` = `solicitado_en` 
WHERE `fecha_creacion` IS NULL;

-- Para transacciones
ALTER TABLE `transacciones` 
ADD COLUMN `fecha_creacion` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER `estado_pago`;

-- Copiar datos existentes de fecha_transaccion a fecha_creacion
UPDATE `transacciones` 
SET `fecha_creacion` = `fecha_transaccion` 
WHERE `fecha_creacion` IS NULL;

-- Verificación
SELECT 'solicitudes_servicio' as tabla, COUNT(*) as registros FROM solicitudes_servicio;
SELECT 'transacciones' as tabla, COUNT(*) as registros FROM transacciones;
