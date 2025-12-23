-- Migración 006: Agregar tabla para documentos del conductor
-- Fecha: 2025-10-25
-- Descripción: Tabla para almacenar rutas de fotos/documentos escaneados

-- Agregar columnas de fotos a detalles_conductor
ALTER TABLE `detalles_conductor`
ADD COLUMN IF NOT EXISTS `licencia_foto_url` VARCHAR(500) NULL COMMENT 'Ruta de la foto de la licencia' AFTER `licencia_categoria`,
ADD COLUMN IF NOT EXISTS `soat_foto_url` VARCHAR(500) NULL COMMENT 'Ruta de la foto del SOAT' AFTER `soat_vencimiento`,
ADD COLUMN IF NOT EXISTS `tecnomecanica_foto_url` VARCHAR(500) NULL COMMENT 'Ruta de la foto de la tecnomecánica' AFTER `tecnomecanica_vencimiento`,
ADD COLUMN IF NOT EXISTS `tarjeta_propiedad_foto_url` VARCHAR(500) NULL COMMENT 'Ruta de la foto de la tarjeta de propiedad' AFTER `tarjeta_propiedad_numero`,
ADD COLUMN IF NOT EXISTS `seguro_foto_url` VARCHAR(500) NULL COMMENT 'Ruta de la foto del seguro' AFTER `vencimiento_seguro`;

-- Crear tabla para historial de documentos
CREATE TABLE IF NOT EXISTS `documentos_conductor_historial` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conductor_id` BIGINT UNSIGNED NOT NULL,
  `tipo_documento` ENUM('licencia', 'soat', 'tecnomecanica', 'tarjeta_propiedad', 'seguro') NOT NULL,
  `url_documento` VARCHAR(500) NOT NULL,
  `fecha_carga` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `activo` TINYINT(1) DEFAULT 1 COMMENT '1 si es el documento actual, 0 si fue reemplazado',
  `reemplazado_en` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conductor_tipo` (`conductor_id`, `tipo_documento`),
  KEY `idx_fecha_carga` (`fecha_carga`),
  KEY `idx_activo` (`activo`),
  CONSTRAINT `fk_doc_historial_conductor` FOREIGN KEY (`conductor_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Historial de documentos subidos por conductores';
