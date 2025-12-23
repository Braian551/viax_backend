-- =====================================================
-- MIGRACIÓN 012: Actualización de Tipos de Vehículos
-- =====================================================
-- Descripción: Actualiza los tipos de vehículos a:
--              - auto (antes carro)
--              - moto
--              - motocarro (nuevo tipo)
-- Fecha: 2025-12-04
-- =====================================================

-- PostgreSQL no soporta ENUM nativo como MySQL, así que usamos VARCHAR con CHECK constraint
-- Primero, eliminamos cualquier constraint existente si existe

DO $$ 
BEGIN
    -- Eliminar constraint existente si existe
    IF EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'check_tipo_vehiculo'
    ) THEN
        ALTER TABLE configuracion_precios DROP CONSTRAINT check_tipo_vehiculo;
    END IF;
END $$;

-- Actualizar los valores existentes antes de aplicar el nuevo constraint
-- Cambiar 'carro' a 'auto'
UPDATE configuracion_precios 
SET tipo_vehiculo = 'auto' 
WHERE tipo_vehiculo = 'carro';

-- Cambiar 'carro_carga' a 'auto' (se elimina este tipo)
UPDATE configuracion_precios 
SET tipo_vehiculo = 'auto' 
WHERE tipo_vehiculo = 'carro_carga';

-- Cambiar 'moto_carga' a 'motocarro'
UPDATE configuracion_precios 
SET tipo_vehiculo = 'motocarro' 
WHERE tipo_vehiculo = 'moto_carga';

-- Agregar el nuevo constraint con los tipos actualizados
ALTER TABLE configuracion_precios 
ADD CONSTRAINT check_tipo_vehiculo 
CHECK (tipo_vehiculo IN ('auto', 'moto', 'motocarro'));

-- Verificar que existen configuraciones para cada tipo, si no, insertarlas
-- Insertar configuración para AUTO si no existe
INSERT INTO configuracion_precios (
    tipo_vehiculo,
    tarifa_base,
    costo_por_km,
    costo_por_minuto,
    tarifa_minima,
    recargo_hora_pico,
    recargo_nocturno,
    recargo_festivo,
    comision_plataforma,
    activo,
    notas
)
SELECT 
    'auto',
    6000.00,    -- Tarifa base
    3000.00,    -- Por kilómetro
    400.00,     -- Por minuto
    9000.00,    -- Mínimo
    20.00,      -- Recargo hora pico (20%)
    25.00,      -- Recargo nocturno (25%)
    30.00,      -- Recargo festivo (30%)
    15.00,      -- Comisión plataforma (15%)
    true,
    'Configuración para servicio de auto - Diciembre 2025'
WHERE NOT EXISTS (
    SELECT 1 FROM configuracion_precios WHERE tipo_vehiculo = 'auto'
);

-- Insertar configuración para MOTO si no existe
INSERT INTO configuracion_precios (
    tipo_vehiculo,
    tarifa_base,
    costo_por_km,
    costo_por_minuto,
    tarifa_minima,
    recargo_hora_pico,
    recargo_nocturno,
    recargo_festivo,
    comision_plataforma,
    activo,
    notas
)
SELECT 
    'moto',
    4000.00,    -- Tarifa base
    2000.00,    -- Por kilómetro
    250.00,     -- Por minuto
    6000.00,    -- Mínimo
    15.00,      -- Recargo hora pico (15%)
    20.00,      -- Recargo nocturno (20%)
    25.00,      -- Recargo festivo (25%)
    15.00,      -- Comisión plataforma (15%)
    true,
    'Configuración para servicio de moto - Diciembre 2025'
WHERE NOT EXISTS (
    SELECT 1 FROM configuracion_precios WHERE tipo_vehiculo = 'moto'
);

-- Insertar configuración para MOTOCARRO si no existe
INSERT INTO configuracion_precios (
    tipo_vehiculo,
    tarifa_base,
    costo_por_km,
    costo_por_minuto,
    tarifa_minima,
    recargo_hora_pico,
    recargo_nocturno,
    recargo_festivo,
    comision_plataforma,
    activo,
    notas
)
SELECT 
    'motocarro',
    5500.00,    -- Tarifa base (intermedio entre moto y auto)
    2500.00,    -- Por kilómetro
    350.00,     -- Por minuto
    8000.00,    -- Mínimo
    18.00,      -- Recargo hora pico (18%)
    22.00,      -- Recargo nocturno (22%)
    28.00,      -- Recargo festivo (28%)
    15.00,      -- Comisión plataforma (15%)
    true,
    'Configuración para servicio de motocarro - Diciembre 2025'
WHERE NOT EXISTS (
    SELECT 1 FROM configuracion_precios WHERE tipo_vehiculo = 'motocarro'
);

-- Eliminar configuraciones de tipos antiguos que ya no se usan
DELETE FROM configuracion_precios 
WHERE tipo_vehiculo NOT IN ('auto', 'moto', 'motocarro');

-- =====================================================
-- También actualizar la tabla de solicitudes_servicio si existe
-- =====================================================
DO $$ 
BEGIN
    -- Verificar si existe la columna tipo_vehiculo en solicitudes_servicio
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'tipo_vehiculo'
    ) THEN
        -- Eliminar constraint existente si existe
        IF EXISTS (
            SELECT 1 FROM pg_constraint 
            WHERE conname = 'check_tipo_vehiculo_solicitud'
        ) THEN
            ALTER TABLE solicitudes_servicio DROP CONSTRAINT check_tipo_vehiculo_solicitud;
        END IF;

        -- Actualizar valores existentes
        UPDATE solicitudes_servicio SET tipo_vehiculo = 'auto' WHERE tipo_vehiculo = 'carro';
        UPDATE solicitudes_servicio SET tipo_vehiculo = 'auto' WHERE tipo_vehiculo = 'carro_carga';
        UPDATE solicitudes_servicio SET tipo_vehiculo = 'motocarro' WHERE tipo_vehiculo = 'moto_carga';

        -- Agregar nuevo constraint
        ALTER TABLE solicitudes_servicio 
        ADD CONSTRAINT check_tipo_vehiculo_solicitud 
        CHECK (tipo_vehiculo IN ('auto', 'moto', 'motocarro'));
    END IF;
END $$;

-- =====================================================
-- Actualizar detalles_conductor si existe
-- =====================================================
DO $$ 
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'detalles_conductor' 
        AND column_name = 'tipo_vehiculo'
    ) THEN
        -- Eliminar constraint existente si existe
        IF EXISTS (
            SELECT 1 FROM pg_constraint 
            WHERE conname = 'check_tipo_vehiculo_conductor'
        ) THEN
            ALTER TABLE detalles_conductor DROP CONSTRAINT check_tipo_vehiculo_conductor;
        END IF;

        -- Actualizar valores existentes
        UPDATE detalles_conductor SET tipo_vehiculo = 'auto' WHERE tipo_vehiculo = 'carro';
        UPDATE detalles_conductor SET tipo_vehiculo = 'auto' WHERE tipo_vehiculo = 'carro_carga';
        UPDATE detalles_conductor SET tipo_vehiculo = 'motocarro' WHERE tipo_vehiculo = 'moto_carga';

        -- Agregar nuevo constraint
        ALTER TABLE detalles_conductor 
        ADD CONSTRAINT check_tipo_vehiculo_conductor 
        CHECK (tipo_vehiculo IN ('auto', 'moto', 'motocarro'));
    END IF;
END $$;

-- =====================================================
-- VERIFICACIÓN
-- =====================================================
-- Mostrar las configuraciones actuales
SELECT id, tipo_vehiculo, tarifa_base, costo_por_km, tarifa_minima, activo, notas
FROM configuracion_precios
ORDER BY tipo_vehiculo;

-- =====================================================
-- INSTRUCCIONES DE INSTALACIÓN
-- =====================================================
-- 1. Ejecutar este script en la base de datos 'viax'
-- 2. Verificar que hay 3 registros: auto, moto, motocarro
-- 3. Los tipos anteriores (carro, moto_carga, carro_carga) serán migrados
-- =====================================================
