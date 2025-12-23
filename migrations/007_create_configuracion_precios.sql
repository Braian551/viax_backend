-- =====================================================
-- MIGRACIÓN 007: Sistema de Configuración de Precios
-- =====================================================
-- Descripción: Crea la tabla para configurar el sistema de precios
--              de los viajes, permitiendo al administrador modificar
--              tarifas, costos base, recargos, etc.
-- Fecha: 2025-10-26
-- =====================================================

-- Crear tabla de configuración de precios
CREATE TABLE IF NOT EXISTS `configuracion_precios` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo_vehiculo` ENUM('moto', 'carro', 'moto_carga', 'carro_carga') NOT NULL DEFAULT 'moto',
  `tarifa_base` DECIMAL(10, 2) NOT NULL DEFAULT 5000.00 COMMENT 'Tarifa mínima del viaje en COP',
  `costo_por_km` DECIMAL(10, 2) NOT NULL DEFAULT 2500.00 COMMENT 'Costo por kilómetro recorrido',
  `costo_por_minuto` DECIMAL(10, 2) NOT NULL DEFAULT 300.00 COMMENT 'Costo por minuto de duración',
  `tarifa_minima` DECIMAL(10, 2) NOT NULL DEFAULT 8000.00 COMMENT 'Precio mínimo total del viaje',
  `tarifa_maxima` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Precio máximo permitido (NULL = sin límite)',
  
  -- Recargos y descuentos
  `recargo_hora_pico` DECIMAL(5, 2) NOT NULL DEFAULT 20.00 COMMENT 'Porcentaje de recargo en hora pico',
  `recargo_nocturno` DECIMAL(5, 2) NOT NULL DEFAULT 25.00 COMMENT 'Porcentaje de recargo nocturno (10pm-6am)',
  `recargo_festivo` DECIMAL(5, 2) NOT NULL DEFAULT 30.00 COMMENT 'Porcentaje de recargo en días festivos',
  `descuento_distancia_larga` DECIMAL(5, 2) NOT NULL DEFAULT 10.00 COMMENT 'Descuento para viajes > umbral_km',
  `umbral_km_descuento` DECIMAL(10, 2) NOT NULL DEFAULT 15.00 COMMENT 'Kilómetros para aplicar descuento',
  
  -- Configuración de horarios
  `hora_pico_inicio_manana` TIME DEFAULT '07:00:00',
  `hora_pico_fin_manana` TIME DEFAULT '09:00:00',
  `hora_pico_inicio_tarde` TIME DEFAULT '17:00:00',
  `hora_pico_fin_tarde` TIME DEFAULT '19:00:00',
  `hora_nocturna_inicio` TIME DEFAULT '22:00:00',
  `hora_nocturna_fin` TIME DEFAULT '06:00:00',
  
  -- Comisiones
  `comision_plataforma` DECIMAL(5, 2) NOT NULL DEFAULT 15.00 COMMENT 'Porcentaje de comisión para la plataforma',
  `comision_metodo_pago` DECIMAL(5, 2) NOT NULL DEFAULT 2.50 COMMENT 'Comisión adicional por pago digital',
  
  -- Límites y restricciones
  `distancia_minima` DECIMAL(10, 2) NOT NULL DEFAULT 1.00 COMMENT 'Distancia mínima del viaje en km',
  `distancia_maxima` DECIMAL(10, 2) NOT NULL DEFAULT 50.00 COMMENT 'Distancia máxima del viaje en km',
  `tiempo_espera_gratis` INT NOT NULL DEFAULT 3 COMMENT 'Minutos de espera gratuita',
  `costo_tiempo_espera` DECIMAL(10, 2) NOT NULL DEFAULT 500.00 COMMENT 'Costo por minuto de espera adicional',
  
  -- Estado y metadatos
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `notas` TEXT COMMENT 'Notas sobre cambios de precios',
  
  PRIMARY KEY (`id`),
  KEY `idx_tipo_vehiculo` (`tipo_vehiculo`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Configuración de precios por tipo de vehículo';

-- Insertar configuraciones por defecto para cada tipo de vehículo
INSERT INTO `configuracion_precios` (
  `tipo_vehiculo`, 
  `tarifa_base`, 
  `costo_por_km`, 
  `costo_por_minuto`, 
  `tarifa_minima`,
  `recargo_hora_pico`,
  `recargo_nocturno`,
  `recargo_festivo`,
  `comision_plataforma`,
  `activo`,
  `notas`
) VALUES 
  -- Configuración para MOTO
  (
    'moto', 
    4000.00,    -- Tarifa base
    2000.00,    -- Por kilómetro
    250.00,     -- Por minuto
    6000.00,    -- Mínimo
    15.00,      -- Recargo hora pico (15%)
    20.00,      -- Recargo nocturno (20%)
    25.00,      -- Recargo festivo (25%)
    15.00,      -- Comisión plataforma (15%)
    1,
    'Configuración inicial para servicio de moto - Octubre 2025'
  ),
  
  -- Configuración para CARRO
  (
    'carro', 
    6000.00,    -- Tarifa base
    3000.00,    -- Por kilómetro
    400.00,     -- Por minuto
    9000.00,    -- Mínimo
    20.00,      -- Recargo hora pico (20%)
    25.00,      -- Recargo nocturno (25%)
    30.00,      -- Recargo festivo (30%)
    15.00,      -- Comisión plataforma (15%)
    1,
    'Configuración inicial para servicio de carro - Octubre 2025'
  ),
  
  -- Configuración para MOTO DE CARGA
  (
    'moto_carga', 
    5000.00,    -- Tarifa base
    2500.00,    -- Por kilómetro
    300.00,     -- Por minuto
    7500.00,    -- Mínimo
    15.00,      -- Recargo hora pico (15%)
    20.00,      -- Recargo nocturno (20%)
    25.00,      -- Recargo festivo (25%)
    15.00,      -- Comisión plataforma (15%)
    1,
    'Configuración inicial para servicio de mensajería en moto - Octubre 2025'
  ),
  
  -- Configuración para CARRO DE CARGA
  (
    'carro_carga', 
    8000.00,    -- Tarifa base
    3500.00,    -- Por kilómetro
    450.00,     -- Por minuto
    12000.00,   -- Mínimo
    20.00,      -- Recargo hora pico (20%)
    25.00,      -- Recargo nocturno (25%)
    30.00,      -- Recargo festivo (30%)
    15.00,      -- Comisión plataforma (15%)
    1,
    'Configuración inicial para servicio de carga en carro - Octubre 2025'
  );

-- Crear tabla de historial de cambios de precios
CREATE TABLE IF NOT EXISTS `historial_precios` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `configuracion_id` BIGINT UNSIGNED NOT NULL,
  `campo_modificado` VARCHAR(100) NOT NULL,
  `valor_anterior` TEXT,
  `valor_nuevo` TEXT,
  `usuario_id` BIGINT UNSIGNED DEFAULT NULL,
  `fecha_cambio` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `motivo` TEXT COMMENT 'Razón del cambio de precio',
  
  PRIMARY KEY (`id`),
  KEY `idx_configuracion` (`configuracion_id`),
  KEY `idx_fecha` (`fecha_cambio`),
  CONSTRAINT `fk_historial_config` FOREIGN KEY (`configuracion_id`) REFERENCES `configuracion_precios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_historial_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Auditoría de cambios en configuración de precios';

-- Crear vista para facilitar consultas de precios activos
CREATE OR REPLACE VIEW `vista_precios_activos` AS
SELECT 
  cp.*,
  CASE 
    WHEN CURRENT_TIME() BETWEEN cp.hora_pico_inicio_manana AND cp.hora_pico_fin_manana THEN 'hora_pico_manana'
    WHEN CURRENT_TIME() BETWEEN cp.hora_pico_inicio_tarde AND cp.hora_pico_fin_tarde THEN 'hora_pico_tarde'
    WHEN CURRENT_TIME() >= cp.hora_nocturna_inicio OR CURRENT_TIME() <= cp.hora_nocturna_fin THEN 'nocturno'
    ELSE 'normal'
  END AS periodo_actual,
  CASE 
    WHEN CURRENT_TIME() BETWEEN cp.hora_pico_inicio_manana AND cp.hora_pico_fin_manana THEN cp.recargo_hora_pico
    WHEN CURRENT_TIME() BETWEEN cp.hora_pico_inicio_tarde AND cp.hora_pico_fin_tarde THEN cp.recargo_hora_pico
    WHEN CURRENT_TIME() >= cp.hora_nocturna_inicio OR CURRENT_TIME() <= cp.hora_nocturna_fin THEN cp.recargo_nocturno
    ELSE 0.00
  END AS recargo_actual
FROM configuracion_precios cp
WHERE cp.activo = 1;

-- =====================================================
-- INSTRUCCIONES DE INSTALACIÓN
-- =====================================================
-- 1. Ejecutar este script en la base de datos 'pingo'
-- 2. Verificar que las tablas se crearon correctamente
-- 3. Confirmar que hay 4 registros en configuracion_precios
-- 4. Los administradores pueden modificar estos valores desde el panel admin
-- =====================================================
