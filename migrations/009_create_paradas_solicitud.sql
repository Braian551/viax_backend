-- Migration: Create paradas_solicitud table
-- Description: Stores intermediate stops for trip requests
-- Date: 2025-11-29

USE `viax`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `paradas_solicitud`;

CREATE TABLE `paradas_solicitud` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `solicitud_id` bigint unsigned NOT NULL,
  `latitud` decimal(10,8) NOT NULL,
  `longitud` decimal(11,8) NOT NULL,
  `direccion` varchar(500) NOT NULL,
  `orden` int NOT NULL COMMENT 'Orden de la parada en la ruta (1, 2, 3...)',
  `estado` enum('pendiente','completada','omitida') DEFAULT 'pendiente',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_solicitud_orden` (`solicitud_id`, `orden`),
  CONSTRAINT `fk_paradas_solicitud` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitudes_servicio` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Paradas intermedias para solicitudes de viaje';

SET FOREIGN_KEY_CHECKS = 1;
