-- =====================================================
-- Migración 029: Normalización de Empresas de Transporte
-- =====================================================
-- Esta migración normaliza la tabla empresas_transporte
-- separando datos en tablas relacionadas para:
-- 1. Contacto de empresa
-- 2. Representante legal
-- 3. Métricas/estadísticas
-- 4. Configuración
-- =====================================================

-- 1. TABLA: empresas_contacto (Información de contacto)
CREATE TABLE IF NOT EXISTS empresas_contacto (
    id BIGSERIAL PRIMARY KEY,
    empresa_id BIGINT NOT NULL REFERENCES empresas_transporte(id) ON DELETE CASCADE,
    
    email VARCHAR(255),
    telefono VARCHAR(50),
    telefono_secundario VARCHAR(50),
    direccion TEXT,
    municipio VARCHAR(100),
    departamento VARCHAR(100),
    
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT ux_empresa_contacto UNIQUE (empresa_id)
);

CREATE INDEX IF NOT EXISTS idx_empresas_contacto_empresa ON empresas_contacto(empresa_id);
CREATE INDEX IF NOT EXISTS idx_empresas_contacto_municipio ON empresas_contacto(municipio);

-- 2. TABLA: empresas_representante (Representante Legal)
CREATE TABLE IF NOT EXISTS empresas_representante (
    id BIGSERIAL PRIMARY KEY,
    empresa_id BIGINT NOT NULL REFERENCES empresas_transporte(id) ON DELETE CASCADE,
    
    nombre VARCHAR(255),
    telefono VARCHAR(50),
    email VARCHAR(255),
    documento_identidad VARCHAR(50),
    cargo VARCHAR(100) DEFAULT 'Representante Legal',
    
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT ux_empresa_representante UNIQUE (empresa_id)
);

CREATE INDEX IF NOT EXISTS idx_empresas_representante_empresa ON empresas_representante(empresa_id);

-- 3. TABLA: empresas_metricas (Métricas calculadas)
CREATE TABLE IF NOT EXISTS empresas_metricas (
    id BIGSERIAL PRIMARY KEY,
    empresa_id BIGINT NOT NULL REFERENCES empresas_transporte(id) ON DELETE CASCADE,
    
    total_conductores INTEGER DEFAULT 0,
    conductores_activos INTEGER DEFAULT 0,
    conductores_pendientes INTEGER DEFAULT 0,
    total_viajes_completados INTEGER DEFAULT 0,
    total_viajes_cancelados INTEGER DEFAULT 0,
    calificacion_promedio DECIMAL(3,2) DEFAULT 0.00,
    total_calificaciones INTEGER DEFAULT 0,
    ingresos_totales DECIMAL(15,2) DEFAULT 0.00,
    
    -- Métricas del mes actual
    viajes_mes INTEGER DEFAULT 0,
    ingresos_mes DECIMAL(15,2) DEFAULT 0.00,
    
    ultima_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT ux_empresa_metricas UNIQUE (empresa_id)
);

CREATE INDEX IF NOT EXISTS idx_empresas_metricas_empresa ON empresas_metricas(empresa_id);

-- 4. TABLA: empresas_configuracion (Configuración operativa)
CREATE TABLE IF NOT EXISTS empresas_configuracion (
    id BIGSERIAL PRIMARY KEY,
    empresa_id BIGINT NOT NULL REFERENCES empresas_transporte(id) ON DELETE CASCADE,
    
    tipos_vehiculo TEXT[], -- Array de tipos: 'moto', 'motocarro', 'taxi', etc.
    zona_operacion TEXT[], -- Municipios donde opera
    horario_operacion JSONB, -- {"lunes": {"inicio": "06:00", "fin": "22:00"}, ...}
    
    acepta_efectivo BOOLEAN DEFAULT TRUE,
    acepta_tarjeta BOOLEAN DEFAULT FALSE,
    acepta_transferencia BOOLEAN DEFAULT FALSE,
    
    radio_maximo_km INTEGER DEFAULT 50,
    tiempo_espera_max_min INTEGER DEFAULT 15,
    
    notificaciones_email BOOLEAN DEFAULT TRUE,
    notificaciones_push BOOLEAN DEFAULT TRUE,
    
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT ux_empresa_configuracion UNIQUE (empresa_id)
);

CREATE INDEX IF NOT EXISTS idx_empresas_configuracion_empresa ON empresas_configuracion(empresa_id);

-- 5. Migrar datos existentes a las nuevas tablas
-- Contacto
INSERT INTO empresas_contacto (empresa_id, email, telefono, telefono_secundario, direccion, municipio, departamento)
SELECT id, email, telefono, telefono_secundario, direccion, municipio, departamento
FROM empresas_transporte
WHERE id NOT IN (SELECT empresa_id FROM empresas_contacto)
ON CONFLICT (empresa_id) DO NOTHING;

-- Representante
INSERT INTO empresas_representante (empresa_id, nombre, telefono, email)
SELECT id, representante_nombre, representante_telefono, representante_email
FROM empresas_transporte
WHERE representante_nombre IS NOT NULL
AND id NOT IN (SELECT empresa_id FROM empresas_representante)
ON CONFLICT (empresa_id) DO NOTHING;

-- Métricas
INSERT INTO empresas_metricas (empresa_id, total_conductores, total_viajes_completados, calificacion_promedio)
SELECT id, total_conductores, total_viajes_completados, calificacion_promedio
FROM empresas_transporte
WHERE id NOT IN (SELECT empresa_id FROM empresas_metricas)
ON CONFLICT (empresa_id) DO NOTHING;

-- Configuración
INSERT INTO empresas_configuracion (empresa_id, tipos_vehiculo)
SELECT id, tipos_vehiculo
FROM empresas_transporte
WHERE tipos_vehiculo IS NOT NULL
AND id NOT IN (SELECT empresa_id FROM empresas_configuracion)
ON CONFLICT (empresa_id) DO NOTHING;

-- 6. Crear vista unificada para compatibilidad hacia atrás
DROP VIEW IF EXISTS v_empresas_completas;
CREATE VIEW v_empresas_completas AS
SELECT 
    e.id,
    e.nombre,
    e.nit,
    e.razon_social,
    e.logo_url,
    e.descripcion,
    e.estado,
    e.verificada,
    e.fecha_verificacion,
    e.verificado_por,
    e.creado_en,
    e.actualizado_en,
    e.creado_por,
    e.notas_admin,
    -- Contacto
    ec.email,
    ec.telefono,
    ec.telefono_secundario,
    ec.direccion,
    ec.municipio,
    ec.departamento,
    -- Representante
    er.nombre AS representante_nombre,
    er.telefono AS representante_telefono,
    er.email AS representante_email,
    er.documento_identidad AS representante_documento,
    -- Métricas
    em.total_conductores,
    em.conductores_activos,
    em.conductores_pendientes,
    em.total_viajes_completados,
    em.calificacion_promedio,
    em.total_calificaciones,
    em.ingresos_totales,
    em.viajes_mes,
    em.ingresos_mes,
    -- Configuración
    ecf.tipos_vehiculo,
    ecf.zona_operacion,
    ecf.acepta_efectivo,
    ecf.acepta_tarjeta,
    ecf.acepta_transferencia
FROM empresas_transporte e
LEFT JOIN empresas_contacto ec ON e.id = ec.empresa_id
LEFT JOIN empresas_representante er ON e.id = er.empresa_id
LEFT JOIN empresas_metricas em ON e.id = em.empresa_id
LEFT JOIN empresas_configuracion ecf ON e.id = ecf.empresa_id;

-- 7. Eliminar columnas redundantes de empresas_transporte (después de migrar)
-- NOTA: Comentado para ejecutar manualmente después de verificar migración
/*
ALTER TABLE empresas_transporte 
DROP COLUMN IF EXISTS email,
DROP COLUMN IF EXISTS telefono,
DROP COLUMN IF EXISTS telefono_secundario,
DROP COLUMN IF EXISTS direccion,
DROP COLUMN IF EXISTS municipio,
DROP COLUMN IF EXISTS departamento,
DROP COLUMN IF EXISTS representante_nombre,
DROP COLUMN IF EXISTS representante_telefono,
DROP COLUMN IF EXISTS representante_email,
DROP COLUMN IF EXISTS tipos_vehiculo,
DROP COLUMN IF EXISTS total_conductores,
DROP COLUMN IF EXISTS total_viajes_completados,
DROP COLUMN IF EXISTS calificacion_promedio;
*/

-- 8. Triggers para actualizar métricas automáticamente
CREATE OR REPLACE FUNCTION actualizar_metricas_empresa()
RETURNS TRIGGER AS $$
BEGIN
    -- Cuando un conductor se vincula/desvincula de una empresa
    IF TG_TABLE_NAME = 'usuarios' THEN
        -- Actualizar empresa anterior (si había)
        IF OLD.empresa_id IS NOT NULL AND OLD.empresa_id != NEW.empresa_id THEN
            UPDATE empresas_metricas 
            SET total_conductores = (
                SELECT COUNT(*) FROM usuarios 
                WHERE empresa_id = OLD.empresa_id AND tipo_usuario = 'conductor'
            ),
            conductores_activos = (
                SELECT COUNT(*) FROM usuarios 
                WHERE empresa_id = OLD.empresa_id AND tipo_usuario = 'conductor' AND es_activo = 1
            ),
            ultima_actualizacion = CURRENT_TIMESTAMP
            WHERE empresa_id = OLD.empresa_id;
        END IF;
        
        -- Actualizar empresa nueva
        IF NEW.empresa_id IS NOT NULL THEN
            -- Insertar registro de métricas si no existe
            INSERT INTO empresas_metricas (empresa_id) 
            VALUES (NEW.empresa_id)
            ON CONFLICT (empresa_id) DO NOTHING;
            
            UPDATE empresas_metricas 
            SET total_conductores = (
                SELECT COUNT(*) FROM usuarios 
                WHERE empresa_id = NEW.empresa_id AND tipo_usuario = 'conductor'
            ),
            conductores_activos = (
                SELECT COUNT(*) FROM usuarios 
                WHERE empresa_id = NEW.empresa_id AND tipo_usuario = 'conductor' AND es_activo = 1
            ),
            ultima_actualizacion = CURRENT_TIMESTAMP
            WHERE empresa_id = NEW.empresa_id;
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_actualizar_metricas_empresa ON usuarios;
CREATE TRIGGER trigger_actualizar_metricas_empresa
    AFTER INSERT OR UPDATE OF empresa_id ON usuarios
    FOR EACH ROW
    WHEN (NEW.tipo_usuario = 'conductor')
    EXECUTE FUNCTION actualizar_metricas_empresa();

-- 9. Función para obtener estadísticas de empresa
CREATE OR REPLACE FUNCTION get_empresa_stats(p_empresa_id BIGINT)
RETURNS JSON AS $$
DECLARE
    v_stats JSON;
BEGIN
    SELECT json_build_object(
        'total_conductores', COALESCE(em.total_conductores, 0),
        'conductores_activos', COALESCE(em.conductores_activos, 0),
        'conductores_pendientes', (
            SELECT COUNT(*) FROM solicitudes_vinculacion_conductor 
            WHERE empresa_id = p_empresa_id AND estado = 'pendiente'
        ),
        'total_viajes', COALESCE(em.total_viajes_completados, 0),
        'calificacion', COALESCE(em.calificacion_promedio, 0),
        'ingresos_mes', COALESCE(em.ingresos_mes, 0)
    ) INTO v_stats
    FROM empresas_metricas em
    WHERE em.empresa_id = p_empresa_id;
    
    IF v_stats IS NULL THEN
        v_stats := json_build_object(
            'total_conductores', 0,
            'conductores_activos', 0,
            'conductores_pendientes', 0,
            'total_viajes', 0,
            'calificacion', 0,
            'ingresos_mes', 0
        );
    END IF;
    
    RETURN v_stats;
END;
$$ LANGUAGE plpgsql;

-- 10. Comentarios descriptivos
COMMENT ON TABLE empresas_contacto IS 'Información de contacto de empresas de transporte';
COMMENT ON TABLE empresas_representante IS 'Información del representante legal de la empresa';
COMMENT ON TABLE empresas_metricas IS 'Métricas y estadísticas calculadas de la empresa';
COMMENT ON TABLE empresas_configuracion IS 'Configuración operativa de la empresa';
COMMENT ON VIEW v_empresas_completas IS 'Vista consolidada de todos los datos de empresa para compatibilidad';

-- 11. Log de migración
INSERT INTO logs_auditoria (usuario_id, accion, entidad, descripcion, fecha_creacion)
VALUES (
    1, 
    'migracion', 
    'sistema', 
    'Migración 029: Normalización de tabla empresas_transporte en 4 tablas relacionadas', 
    CURRENT_TIMESTAMP
);

-- =====================================================
-- RESUMEN DE NORMALIZACIÓN:
-- empresas_transporte: Datos básicos (nombre, nit, logo, estado)
-- empresas_contacto: Email, teléfonos, dirección
-- empresas_representante: Representante legal
-- empresas_metricas: Estadísticas calculadas
-- empresas_configuracion: Configuración operativa
-- v_empresas_completas: Vista para compatibilidad
-- =====================================================
