-- Migration: Device-based security tables
-- Creates user_devices table to track trusted devices and login security states
-- Safe to run multiple times (will ignore if table exists)

CREATE TABLE IF NOT EXISTS `user_devices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `device_uuid` VARCHAR(100) NOT NULL,
  `first_seen` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `trusted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 cuando el usuario verificó el dispositivo vía código',
  `fail_attempts` INT NOT NULL DEFAULT 0 COMMENT 'Intentos de contraseña fallidos consecutivos en este dispositivo',
  `locked_until` TIMESTAMP NULL DEFAULT NULL COMMENT 'Si se exceden intentos, dispositivo bloqueado hasta esta fecha',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_device_unique` (`user_id`,`device_uuid`),
  KEY `idx_device_uuid` (`device_uuid`),
  KEY `idx_trusted` (`trusted`),
  CONSTRAINT `fk_user_devices_usuario` FOREIGN KEY (`user_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Optional: table for archival of device locks (future use)
-- CREATE TABLE IF NOT EXISTS `user_device_lock_events` (
--   `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
--   `user_device_id` BIGINT UNSIGNED NOT NULL,
--   `fail_count` INT NOT NULL,
--   `locked_until` TIMESTAMP NULL,
--   `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
--   PRIMARY KEY (`id`),
--   KEY `idx_device_lock` (`user_device_id`),
--   CONSTRAINT `fk_lock_events_device` FOREIGN KEY (`user_device_id`) REFERENCES `user_devices`(`id`) ON DELETE CASCADE
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
