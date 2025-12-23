-- Ejecutar Migration 004: Fix fecha_creacion columns
-- Ejecuta este archivo directamente en MySQL Workbench o línea de comandos

USE pingo;

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
SELECT 'Migración 004 completada exitosamente' as status;
SELECT 'solicitudes_servicio' as tabla, 
       COUNT(*) as registros,
       COUNT(fecha_creacion) as con_fecha_creacion
FROM solicitudes_servicio;

SELECT 'transacciones' as tabla, 
       COUNT(*) as registros,
       COUNT(fecha_creacion) as con_fecha_creacion
FROM transacciones;
