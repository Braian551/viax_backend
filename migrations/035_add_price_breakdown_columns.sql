-- ============================================================================
-- Migración 035: Agregar columnas de desglose de precio y comisión real
-- ============================================================================
-- Este script agrega columnas para almacenar el desglose completo del precio
-- de un viaje incluyendo todos los recargos, descuentos y la comisión real
-- aplicada por la empresa.
-- ============================================================================

-- Agregar columnas de desglose a viaje_resumen_tracking
ALTER TABLE viaje_resumen_tracking 
ADD COLUMN IF NOT EXISTS tarifa_base DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS precio_distancia DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS precio_tiempo DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS recargo_nocturno DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS recargo_hora_pico DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS recargo_festivo DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS recargo_espera DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS tiempo_espera_min INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS descuento_distancia_larga DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS subtotal_sin_recargos DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS total_recargos DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS tipo_recargo VARCHAR(30) DEFAULT 'normal',
ADD COLUMN IF NOT EXISTS aplico_tarifa_minima BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS comision_plataforma_porcentaje DECIMAL(5,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS comision_plataforma_valor DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS ganancia_conductor DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS comision_admin_porcentaje DECIMAL(5,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS comision_admin_valor DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS ganancia_empresa DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS empresa_id BIGINT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS config_precios_id BIGINT DEFAULT NULL;

-- Agregar comentarios explicativos
COMMENT ON COLUMN viaje_resumen_tracking.tarifa_base IS 'Tarifa base del tipo de vehículo';
COMMENT ON COLUMN viaje_resumen_tracking.precio_distancia IS 'Precio calculado por distancia (km * costo_por_km)';
COMMENT ON COLUMN viaje_resumen_tracking.precio_tiempo IS 'Precio calculado por tiempo (min * costo_por_minuto)';
COMMENT ON COLUMN viaje_resumen_tracking.recargo_nocturno IS 'Recargo aplicado por horario nocturno';
COMMENT ON COLUMN viaje_resumen_tracking.recargo_hora_pico IS 'Recargo aplicado por hora pico';
COMMENT ON COLUMN viaje_resumen_tracking.recargo_festivo IS 'Recargo aplicado por día festivo';
COMMENT ON COLUMN viaje_resumen_tracking.recargo_espera IS 'Recargo por tiempo de espera adicional';
COMMENT ON COLUMN viaje_resumen_tracking.tiempo_espera_min IS 'Tiempo de espera en minutos (fuera del tiempo gratis)';
COMMENT ON COLUMN viaje_resumen_tracking.descuento_distancia_larga IS 'Descuento aplicado por distancia larga';
COMMENT ON COLUMN viaje_resumen_tracking.subtotal_sin_recargos IS 'Suma de base + distancia + tiempo';
COMMENT ON COLUMN viaje_resumen_tracking.total_recargos IS 'Suma total de todos los recargos aplicados';
COMMENT ON COLUMN viaje_resumen_tracking.tipo_recargo IS 'Tipo de recargo aplicado: normal, nocturno, hora_pico_manana, hora_pico_tarde, festivo';
COMMENT ON COLUMN viaje_resumen_tracking.aplico_tarifa_minima IS 'Indica si se aplicó la tarifa mínima en lugar del cálculo';
COMMENT ON COLUMN viaje_resumen_tracking.comision_plataforma_porcentaje IS 'Porcentaje de comisión que la empresa cobra al conductor';
COMMENT ON COLUMN viaje_resumen_tracking.comision_plataforma_valor IS 'Valor en pesos de la comisión cobrada al conductor';
COMMENT ON COLUMN viaje_resumen_tracking.ganancia_conductor IS 'Ganancia neta del conductor después de comisión de empresa';
COMMENT ON COLUMN viaje_resumen_tracking.comision_admin_porcentaje IS 'Porcentaje de comisión que el admin cobra a la empresa';
COMMENT ON COLUMN viaje_resumen_tracking.comision_admin_valor IS 'Valor en pesos de la comisión cobrada a la empresa';
COMMENT ON COLUMN viaje_resumen_tracking.ganancia_empresa IS 'Ganancia neta de la empresa después de comisión del admin';
COMMENT ON COLUMN viaje_resumen_tracking.empresa_id IS 'ID de la empresa a la que pertenece el conductor';
COMMENT ON COLUMN viaje_resumen_tracking.config_precios_id IS 'ID de la configuración de precios utilizada';

-- Agregar FK a empresa
ALTER TABLE viaje_resumen_tracking 
ADD CONSTRAINT fk_tracking_empresa 
FOREIGN KEY (empresa_id) REFERENCES empresas_transporte(id) ON DELETE SET NULL;

-- Agregar FK a configuracion_precios
ALTER TABLE viaje_resumen_tracking 
ADD CONSTRAINT fk_tracking_config_precios 
FOREIGN KEY (config_precios_id) REFERENCES configuracion_precios(id) ON DELETE SET NULL;

-- Crear índice para búsquedas por empresa
CREATE INDEX IF NOT EXISTS idx_tracking_empresa ON viaje_resumen_tracking(empresa_id);

-- ============================================================================
-- Agregar columnas de desglose a solicitudes_servicio para referencia rápida
-- ============================================================================

ALTER TABLE solicitudes_servicio
ADD COLUMN IF NOT EXISTS desglose_precio JSONB DEFAULT NULL;

COMMENT ON COLUMN solicitudes_servicio.desglose_precio IS 'JSON con desglose completo del precio: base, distancia, tiempo, recargos, comisión, etc.';

-- ============================================================================
-- Índice GIN para búsquedas en el JSON de desglose
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_solicitudes_desglose ON solicitudes_servicio USING GIN (desglose_precio);
