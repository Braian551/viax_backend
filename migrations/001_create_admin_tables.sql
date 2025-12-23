-- Migracion 001: Tablas para modulo de administrador
-- Fecha: 2025-10-22
-- Descripcion: Crea tablas para estadisticas, logs de auditoria y configuraciones

-- Tabla de logs de auditoria (registro de acciones importantes)
CREATE TABLE IF NOT EXISTS `logs_auditoria` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` BIGINT UNSIGNED NULL,
  `accion` VARCHAR(100) NOT NULL COMMENT 'Tipo de accion realizada',
  `entidad` VARCHAR(100) NULL COMMENT 'Tabla o entidad afectada',
  `entidad_id` BIGINT UNSIGNED NULL COMMENT 'ID del registro afectado',
  `descripcion` TEXT NULL COMMENT 'Descripcion detallada de la accion',
  `ip_address` VARCHAR(45) NULL COMMENT 'Direccion IP del usuario',
  `user_agent` VARCHAR(255) NULL COMMENT 'Navegador/dispositivo usado',
  `datos_anteriores` JSON NULL COMMENT 'Datos antes del cambio',
  `datos_nuevos` JSON NULL COMMENT 'Datos despues del cambio',
  `fecha_creacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_usuario_id` (`usuario_id`),
  INDEX `idx_accion` (`accion`),
  INDEX `idx_fecha` (`fecha_creacion`),
  CONSTRAINT `fk_logs_usuario` FOREIGN KEY (`usuario_id`) 
    REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Registro de todas las acciones importantes del sistema';

-- Tabla de estadisticas del sistema
CREATE TABLE IF NOT EXISTS `estadisticas_sistema` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fecha` DATE NOT NULL,
  `total_usuarios` INT UNSIGNED DEFAULT 0,
  `total_clientes` INT UNSIGNED DEFAULT 0,
  `total_conductores` INT UNSIGNED DEFAULT 0,
  `total_administradores` INT UNSIGNED DEFAULT 0,
  `usuarios_activos_dia` INT UNSIGNED DEFAULT 0,
  `nuevos_registros_dia` INT UNSIGNED DEFAULT 0,
  `total_solicitudes` INT UNSIGNED DEFAULT 0,
  `solicitudes_completadas` INT UNSIGNED DEFAULT 0,
  `solicitudes_canceladas` INT UNSIGNED DEFAULT 0,
  `ingresos_totales` DECIMAL(10,2) DEFAULT 0.00,
  `ingresos_dia` DECIMAL(10,2) DEFAULT 0.00,
  `fecha_creacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Estadisticas diarias del sistema';

-- Tabla de configuraciones de la aplicacion
CREATE TABLE IF NOT EXISTS `configuraciones_app` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `clave` VARCHAR(100) NOT NULL COMMENT 'Nombre de la configuracion',
  `valor` TEXT NULL COMMENT 'Valor de la configuracion',
  `tipo` ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
  `categoria` VARCHAR(50) NULL COMMENT 'Categoria de la config (pricing, system, etc)',
  `descripcion` TEXT NULL COMMENT 'Descripcion de que hace esta config',
  `es_publica` TINYINT(1) DEFAULT 0 COMMENT '1 si puede verse en el frontend',
  `fecha_creacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_clave` (`clave`),
  INDEX `idx_categoria` (`categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Configuraciones globales de la aplicacion';

-- Insertar configuraciones iniciales
INSERT INTO `configuraciones_app` (`clave`, `valor`, `tipo`, `categoria`, `descripcion`, `es_publica`) VALUES
('app_nombre', 'PinGo', 'string', 'sistema', 'Nombre de la aplicacion', 1),
('app_version', '1.0.0', 'string', 'sistema', 'Version actual de la aplicacion', 1),
('mantenimiento_activo', 'false', 'boolean', 'sistema', 'Indica si la app esta en mantenimiento', 1),
('precio_base_km', '2500', 'number', 'precios', 'Precio base por kilometro en COP', 0),
('precio_minimo_viaje', '5000', 'number', 'precios', 'Precio minimo de un viaje en COP', 0),
('comision_plataforma', '15', 'number', 'precios', 'Porcentaje de comision de la plataforma', 0),
('radio_busqueda_conductores', '5000', 'number', 'sistema', 'Radio en metros para buscar conductores', 0),
('tiempo_expiracion_solicitud', '300', 'number', 'sistema', 'Tiempo en segundos antes de expirar solicitud', 0)
ON DUPLICATE KEY UPDATE `fecha_actualizacion` = CURRENT_TIMESTAMP;

-- Tabla de reportes de usuarios
CREATE TABLE IF NOT EXISTS `reportes_usuarios` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_reportante_id` BIGINT UNSIGNED NOT NULL,
  `usuario_reportado_id` BIGINT UNSIGNED NOT NULL,
  `solicitud_id` BIGINT UNSIGNED NULL,
  `tipo_reporte` ENUM('conducta_inapropiada', 'fraude', 'seguridad', 'otro') NOT NULL,
  `descripcion` TEXT NOT NULL,
  `estado` ENUM('pendiente', 'en_revision', 'resuelto', 'rechazado') DEFAULT 'pendiente',
  `notas_admin` TEXT NULL,
  `admin_revisor_id` BIGINT UNSIGNED NULL,
  `fecha_creacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `fecha_resolucion` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_reportante` (`usuario_reportante_id`),
  INDEX `idx_reportado` (`usuario_reportado_id`),
  INDEX `idx_estado` (`estado`),
  CONSTRAINT `fk_reporte_reportante` FOREIGN KEY (`usuario_reportante_id`) 
    REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reporte_reportado` FOREIGN KEY (`usuario_reportado_id`) 
    REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reporte_solicitud` FOREIGN KEY (`solicitud_id`) 
    REFERENCES `solicitudes_servicio` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reporte_admin` FOREIGN KEY (`admin_revisor_id`) 
    REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Reportes de usuarios sobre comportamiento inadecuado';
