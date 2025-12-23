-- =====================================================
-- Migración 017: Corrección del Sistema de Pagos y Ganancias
-- =====================================================
-- Corrige la inconsistencia entre:
-- - solicitudes_servicio (no tiene campos de precio)
-- - transacciones (no tiene monto_conductor ni estado correcto)
-- - get_ganancias/get_historial que buscan estos campos
-- =====================================================

-- 1. Agregar campos de precio a solicitudes_servicio
ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS precio_estimado NUMERIC(10,2) DEFAULT 0;

ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS precio_final NUMERIC(10,2) DEFAULT 0;

ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS metodo_pago VARCHAR(50) DEFAULT 'efectivo';

ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS pago_confirmado BOOLEAN DEFAULT FALSE;

ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS pago_confirmado_en TIMESTAMP;

-- 2. Agregar campos faltantes a transacciones
ALTER TABLE transacciones 
ADD COLUMN IF NOT EXISTS monto_conductor NUMERIC(10,2) DEFAULT 0;

ALTER TABLE transacciones 
ADD COLUMN IF NOT EXISTS estado VARCHAR(50) DEFAULT 'pendiente';

ALTER TABLE transacciones 
ADD COLUMN IF NOT EXISTS comision_plataforma NUMERIC(10,2) DEFAULT 0;

-- 3. Crear índices para mejor rendimiento
CREATE INDEX IF NOT EXISTS idx_transacciones_conductor ON transacciones(conductor_id);
CREATE INDEX IF NOT EXISTS idx_transacciones_estado ON transacciones(estado);
CREATE INDEX IF NOT EXISTS idx_transacciones_fecha ON transacciones(fecha_transaccion);
CREATE INDEX IF NOT EXISTS idx_solicitudes_estado ON solicitudes_servicio(estado);
CREATE INDEX IF NOT EXISTS idx_solicitudes_precio ON solicitudes_servicio(precio_final);

-- 4. Crear tabla pagos_viaje si no existe
CREATE TABLE IF NOT EXISTS pagos_viaje (
    id SERIAL PRIMARY KEY,
    solicitud_id INT UNIQUE NOT NULL,
    conductor_id INT NOT NULL,
    cliente_id INT,
    monto NUMERIC(10,2) NOT NULL,
    metodo_pago VARCHAR(50) DEFAULT 'efectivo',
    estado VARCHAR(20) DEFAULT 'pendiente',
    confirmado_en TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_pago_solicitud 
        FOREIGN KEY (solicitud_id) REFERENCES solicitudes_servicio(id) ON DELETE CASCADE,
    CONSTRAINT fk_pago_conductor 
        FOREIGN KEY (conductor_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- 5. Actualizar transacciones existentes con estado correcto
UPDATE transacciones 
SET estado = 'completada' 
WHERE estado_pago = 'completado' OR estado_pago = 'pagado';

UPDATE transacciones 
SET estado = 'pendiente' 
WHERE estado IS NULL OR estado = '';

-- 6. Calcular monto_conductor (90% del total, 10% comisión plataforma)
UPDATE transacciones 
SET monto_conductor = COALESCE(monto_total, 0) * 0.90,
    comision_plataforma = COALESCE(monto_total, 0) * 0.10
WHERE monto_conductor IS NULL OR monto_conductor = 0;

-- Comentarios
COMMENT ON COLUMN solicitudes_servicio.precio_estimado IS 'Precio calculado antes de iniciar el viaje';
COMMENT ON COLUMN solicitudes_servicio.precio_final IS 'Precio final del viaje (puede variar por ruta real)';
COMMENT ON COLUMN transacciones.monto_conductor IS 'Monto que recibe el conductor (total menos comisión)';
COMMENT ON COLUMN transacciones.estado IS 'Estado de la transacción: pendiente, completada, cancelada';
