-- =====================================================
-- Migración 018: Sistema de Empresas de Transporte
-- =====================================================
-- Este sistema permite registrar empresas de transporte
-- (motos, motocarros, etc.) para que conductores y 
-- clientes puedan seleccionar su empresa preferida.
-- =====================================================

-- Tabla de empresas de transporte
CREATE TABLE IF NOT EXISTS empresas_transporte (
    id BIGSERIAL PRIMARY KEY,
    
    -- Información básica de la empresa
    nombre VARCHAR(255) NOT NULL,
    nit VARCHAR(50) UNIQUE,
    razon_social VARCHAR(255),
    
    -- Información de contacto
    email VARCHAR(255),
    telefono VARCHAR(50),
    telefono_secundario VARCHAR(50),
    direccion TEXT,
    municipio VARCHAR(100),
    departamento VARCHAR(100),
    
    -- Representante legal
    representante_nombre VARCHAR(255),
    representante_telefono VARCHAR(50),
    representante_email VARCHAR(255),
    
    -- Configuración
    tipos_vehiculo TEXT[], -- Array de tipos: 'moto', 'motocarro', 'taxi', etc.
    logo_url VARCHAR(500),
    descripcion TEXT,
    
    -- Estado
    estado VARCHAR(50) DEFAULT 'activo', -- 'activo', 'inactivo', 'suspendido', 'pendiente'
    verificada BOOLEAN DEFAULT FALSE,
    fecha_verificacion TIMESTAMP,
    verificado_por BIGINT REFERENCES usuarios(id),
    
    -- Métricas (se actualizan automáticamente)
    total_conductores INTEGER DEFAULT 0,
    total_viajes_completados INTEGER DEFAULT 0,
    calificacion_promedio DECIMAL(3,2) DEFAULT 0.00,
    
    -- Timestamps
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    creado_por BIGINT REFERENCES usuarios(id),
    
    -- Notas admin
    notas_admin TEXT
);

-- Índices para búsquedas eficientes
CREATE INDEX IF NOT EXISTS idx_empresas_transporte_nombre ON empresas_transporte(nombre);
CREATE INDEX IF NOT EXISTS idx_empresas_transporte_nit ON empresas_transporte(nit);
CREATE INDEX IF NOT EXISTS idx_empresas_transporte_estado ON empresas_transporte(estado);
CREATE INDEX IF NOT EXISTS idx_empresas_transporte_municipio ON empresas_transporte(municipio);

-- Agregar columna empresa_id a usuarios para conductores
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' 
                   AND column_name = 'empresa_id') THEN
        ALTER TABLE usuarios 
        ADD COLUMN empresa_id BIGINT REFERENCES empresas_transporte(id);
        
        CREATE INDEX IF NOT EXISTS idx_usuarios_empresa_id ON usuarios(empresa_id);
    END IF;
END $$;

-- Agregar columna empresa_preferida_id a usuarios para clientes
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' 
                   AND column_name = 'empresa_preferida_id') THEN
        ALTER TABLE usuarios 
        ADD COLUMN empresa_preferida_id BIGINT REFERENCES empresas_transporte(id);
        
        CREATE INDEX IF NOT EXISTS idx_usuarios_empresa_preferida ON usuarios(empresa_preferida_id);
    END IF;
END $$;

-- Trigger para actualizar actualizado_en automáticamente
CREATE OR REPLACE FUNCTION update_empresas_transporte_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.actualizado_en = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_update_empresas_timestamp ON empresas_transporte;
CREATE TRIGGER trigger_update_empresas_timestamp
    BEFORE UPDATE ON empresas_transporte
    FOR EACH ROW
    EXECUTE FUNCTION update_empresas_transporte_timestamp();

-- Comentarios de documentación
COMMENT ON TABLE empresas_transporte IS 'Tabla para almacenar empresas de transporte registradas';
COMMENT ON COLUMN empresas_transporte.tipos_vehiculo IS 'Array de tipos de vehículos que maneja la empresa';
COMMENT ON COLUMN empresas_transporte.estado IS 'Estado de la empresa: activo, inactivo, suspendido, pendiente';
