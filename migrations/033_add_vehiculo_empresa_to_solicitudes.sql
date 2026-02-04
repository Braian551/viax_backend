-- =====================================================
-- Migración 033: Agregar tipo_vehiculo y empresa_id a solicitudes_servicio
-- =====================================================
-- Este cambio permite filtrar solicitudes por tipo de vehículo y empresa
-- para que solo los conductores con el vehículo correcto de la empresa
-- correcta reciban las solicitudes correspondientes.
-- =====================================================

-- 1. Agregar columna tipo_vehiculo si no existe
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'tipo_vehiculo'
    ) THEN
        ALTER TABLE solicitudes_servicio 
        ADD COLUMN tipo_vehiculo VARCHAR(30) DEFAULT 'moto';
        
        RAISE NOTICE 'Columna tipo_vehiculo agregada a solicitudes_servicio';
    ELSE
        RAISE NOTICE 'Columna tipo_vehiculo ya existe en solicitudes_servicio';
    END IF;
END $$;

-- 2. Agregar columna empresa_id si no existe
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'empresa_id'
    ) THEN
        ALTER TABLE solicitudes_servicio 
        ADD COLUMN empresa_id BIGINT DEFAULT NULL;
        
        RAISE NOTICE 'Columna empresa_id agregada a solicitudes_servicio';
    ELSE
        RAISE NOTICE 'Columna empresa_id ya existe en solicitudes_servicio';
    END IF;
END $$;

-- 3. Agregar columna precio_estimado si no existe
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'precio_estimado'
    ) THEN
        ALTER TABLE solicitudes_servicio 
        ADD COLUMN precio_estimado NUMERIC(10,2) DEFAULT 0;
        
        RAISE NOTICE 'Columna precio_estimado agregada a solicitudes_servicio';
    ELSE
        RAISE NOTICE 'Columna precio_estimado ya existe en solicitudes_servicio';
    END IF;
END $$;

-- 4. Agregar columna metodo_pago si no existe
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'metodo_pago'
    ) THEN
        ALTER TABLE solicitudes_servicio 
        ADD COLUMN metodo_pago VARCHAR(30) DEFAULT 'efectivo';
        
        RAISE NOTICE 'Columna metodo_pago agregada a solicitudes_servicio';
    ELSE
        RAISE NOTICE 'Columna metodo_pago ya existe en solicitudes_servicio';
    END IF;
END $$;

-- 5. Agregar columna conductor_id si no existe
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'solicitudes_servicio' 
        AND column_name = 'conductor_id'
    ) THEN
        ALTER TABLE solicitudes_servicio 
        ADD COLUMN conductor_id BIGINT DEFAULT NULL;
        
        RAISE NOTICE 'Columna conductor_id agregada a solicitudes_servicio';
    ELSE
        RAISE NOTICE 'Columna conductor_id ya existe en solicitudes_servicio';
    END IF;
END $$;

-- 6. Crear índice para búsquedas eficientes por empresa y tipo de vehículo
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE indexname = 'idx_solicitudes_empresa_vehiculo'
    ) THEN
        CREATE INDEX idx_solicitudes_empresa_vehiculo 
        ON solicitudes_servicio(empresa_id, tipo_vehiculo)
        WHERE estado = 'pendiente';
        
        RAISE NOTICE 'Índice idx_solicitudes_empresa_vehiculo creado';
    END IF;
END $$;

-- 7. Crear índice para búsquedas por estado y fecha
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE indexname = 'idx_solicitudes_estado_fecha'
    ) THEN
        CREATE INDEX idx_solicitudes_estado_fecha 
        ON solicitudes_servicio(estado, solicitado_en DESC);
        
        RAISE NOTICE 'Índice idx_solicitudes_estado_fecha creado';
    END IF;
END $$;

-- 8. Crear constraint para tipos de vehículo válidos
DO $$ 
BEGIN
    -- Eliminar constraint anterior si existe
    IF EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'check_tipo_vehiculo_solicitud'
    ) THEN
        ALTER TABLE solicitudes_servicio DROP CONSTRAINT check_tipo_vehiculo_solicitud;
    END IF;
    
    -- Crear nuevo constraint
    ALTER TABLE solicitudes_servicio 
    ADD CONSTRAINT check_tipo_vehiculo_solicitud 
    CHECK (tipo_vehiculo IS NULL OR tipo_vehiculo IN ('moto', 'auto', 'motocarro', 'taxi'));
    
    RAISE NOTICE 'Constraint check_tipo_vehiculo_solicitud actualizado';
END $$;

-- 9. Agregar foreign key a empresas_transporte si existe la tabla
DO $$ 
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'empresas_transporte') THEN
        -- Verificar si el FK ya existe
        IF NOT EXISTS (
            SELECT 1 FROM pg_constraint 
            WHERE conname = 'fk_solicitudes_empresa'
        ) THEN
            ALTER TABLE solicitudes_servicio 
            ADD CONSTRAINT fk_solicitudes_empresa 
            FOREIGN KEY (empresa_id) REFERENCES empresas_transporte(id) ON DELETE SET NULL;
            
            RAISE NOTICE 'Foreign key fk_solicitudes_empresa creado';
        END IF;
    END IF;
END $$;

-- 10. Verificación final
DO $$ 
DECLARE
    v_columns TEXT;
BEGIN
    SELECT string_agg(column_name, ', ') INTO v_columns
    FROM information_schema.columns 
    WHERE table_name = 'solicitudes_servicio'
    AND column_name IN ('tipo_vehiculo', 'empresa_id', 'precio_estimado', 'metodo_pago', 'conductor_id');
    
    RAISE NOTICE 'Columnas verificadas en solicitudes_servicio: %', v_columns;
END $$;
