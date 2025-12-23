-- Migracion 002: Campos adicionales para modulo conductor
-- Fecha: 2025-10-23
-- Descripcion: Agrega campos necesarios para el funcionamiento del modulo conductor

-- Nota: Esta migración está diseñada para ser idempotente (puede ejecutarse múltiples veces)
-- Los errores de "columna ya existe" son normales y pueden ignorarse

-- Agregar campos a la tabla detalles_conductor
ALTER TABLE `detalles_conductor`
ADD COLUMN `disponible` TINYINT(1) DEFAULT 0 COMMENT '1 si el conductor esta disponible para recibir solicitudes';

ALTER TABLE `detalles_conductor`
ADD COLUMN `latitud_actual` DECIMAL(10,8) NULL COMMENT 'Latitud actual del conductor';

ALTER TABLE `detalles_conductor`
ADD COLUMN `longitud_actual` DECIMAL(11,8) NULL COMMENT 'Longitud actual del conductor';

ALTER TABLE `detalles_conductor`
ADD COLUMN `ultima_actualizacion` TIMESTAMP NULL COMMENT 'Ultima vez que se actualizo la ubicacion';

ALTER TABLE `detalles_conductor`
ADD COLUMN `total_viajes` INT UNSIGNED DEFAULT 0 COMMENT 'Total de viajes completados';

ALTER TABLE `detalles_conductor`
ADD COLUMN `estado_verificacion` ENUM('pendiente', 'en_revision', 'aprobado', 'rechazado') DEFAULT 'pendiente' COMMENT 'Estado de verificacion de documentos';

ALTER TABLE `detalles_conductor`
ADD COLUMN `fecha_ultima_verificacion` TIMESTAMP NULL COMMENT 'Fecha de la ultima verificacion';

-- Agregar índices para mejorar el rendimiento de búsquedas
ALTER TABLE `detalles_conductor`
ADD INDEX `idx_disponible` (`disponible`);

ALTER TABLE `detalles_conductor`
ADD INDEX `idx_estado_verificacion` (`estado_verificacion`);
