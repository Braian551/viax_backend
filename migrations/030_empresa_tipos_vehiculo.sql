-- =====================================================
-- Migración 030: Sistema de Tipos de Vehículo por Empresa
-- =====================================================
-- Esta migración crea un sistema normalizado para gestionar
-- los tipos de vehículo habilitados por empresa.
-- 
-- Características:
-- - Tabla normalizada para tipos de vehículo de empresa
-- - Estados activo/inactivo con historial
-- - Relación con conductores para notificaciones
-- - Triggers para actualizar contadores
-- =====================================================

-- 1. TABLA CATÁLOGO: Tipos de vehículo disponibles en el sistema
CREATE TABLE IF NOT EXISTS catalogo_tipos_vehiculo (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    icono VARCHAR(100),
    orden INTEGER DEFAULT 0,
    activo BOOLEAN DEFAULT TRUE,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insertar tipos de vehículo por defecto
INSERT INTO catalogo_tipos_vehiculo (codigo, nombre, descripcion, icono, orden) VALUES
    ('moto', 'Moto', 'Motocicletas para transporte rápido', 'two_wheeler', 1),
    ('auto', 'Auto', 'Automóviles sedan y similares', 'directions_car', 2),
    ('motocarro', 'Motocarro', 'Motocarros de carga y pasajeros', 'electric_rickshaw', 3)
ON CONFLICT (codigo) DO NOTHING;

-- 2. TABLA PRINCIPAL: Tipos de vehículo por empresa
CREATE TABLE IF NOT EXISTS empresa_tipos_vehiculo (
    id BIGSERIAL PRIMARY KEY,
    empresa_id BIGINT NOT NULL REFERENCES empresas_transporte(id) ON DELETE CASCADE,
    tipo_vehiculo_codigo VARCHAR(50) NOT NULL,
    
    -- Estado del tipo de vehículo para esta empresa
    activo BOOLEAN DEFAULT TRUE,
    fecha_activacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_desactivacion TIMESTAMP,
    
    -- Quién realizó el cambio
    activado_por BIGINT REFERENCES usuarios(id),
    desactivado_por BIGINT REFERENCES usuarios(id),
    
    -- Motivo de desactivación (opcional)
    motivo_desactivacion TEXT,
    
    -- Contadores (se actualizan con triggers)
    conductores_activos INTEGER DEFAULT 0,
    viajes_completados INTEGER DEFAULT 0,
    
    -- Timestamps
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Restricción única: una empresa solo puede tener un registro por tipo
    CONSTRAINT ux_empresa_tipo_vehiculo UNIQUE (empresa_id, tipo_vehiculo_codigo),
    
    -- Foreign key al catálogo
    CONSTRAINT fk_tipo_vehiculo_catalogo FOREIGN KEY (tipo_vehiculo_codigo) 
        REFERENCES catalogo_tipos_vehiculo(codigo) ON UPDATE CASCADE
);

-- Índices para búsquedas eficientes
CREATE INDEX IF NOT EXISTS idx_etv_empresa ON empresa_tipos_vehiculo(empresa_id);
CREATE INDEX IF NOT EXISTS idx_etv_tipo ON empresa_tipos_vehiculo(tipo_vehiculo_codigo);
CREATE INDEX IF NOT EXISTS idx_etv_activo ON empresa_tipos_vehiculo(activo);
CREATE INDEX IF NOT EXISTS idx_etv_empresa_activo ON empresa_tipos_vehiculo(empresa_id, activo);

-- 3. TABLA HISTORIAL: Log de cambios de estado
CREATE TABLE IF NOT EXISTS empresa_tipos_vehiculo_historial (
    id BIGSERIAL PRIMARY KEY,
    empresa_tipo_vehiculo_id BIGINT NOT NULL REFERENCES empresa_tipos_vehiculo(id) ON DELETE CASCADE,
    empresa_id BIGINT NOT NULL,
    tipo_vehiculo_codigo VARCHAR(50) NOT NULL,
    
    -- Cambio realizado
    accion VARCHAR(20) NOT NULL, -- 'activado', 'desactivado'
    estado_anterior BOOLEAN,
    estado_nuevo BOOLEAN,
    
    -- Quién y cuándo
    realizado_por BIGINT REFERENCES usuarios(id),
    motivo TEXT,
    fecha_cambio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Contexto adicional
    ip_address VARCHAR(45),
    user_agent TEXT,
    
    -- Conductores afectados al momento del cambio
    conductores_afectados INTEGER DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_etvh_empresa ON empresa_tipos_vehiculo_historial(empresa_id);
CREATE INDEX IF NOT EXISTS idx_etvh_fecha ON empresa_tipos_vehiculo_historial(fecha_cambio);

-- 4. TABLA: Notificaciones enviadas a conductores
CREATE TABLE IF NOT EXISTS empresa_vehiculo_notificaciones (
    id BIGSERIAL PRIMARY KEY,
    historial_id BIGINT REFERENCES empresa_tipos_vehiculo_historial(id),
    conductor_id BIGINT NOT NULL REFERENCES usuarios(id),
    empresa_id BIGINT NOT NULL,
    tipo_vehiculo_codigo VARCHAR(50) NOT NULL,
    
    -- Estado de la notificación
    tipo_notificacion VARCHAR(50) NOT NULL, -- 'email', 'push', 'sms'
    estado VARCHAR(20) DEFAULT 'pendiente', -- 'pendiente', 'enviada', 'fallida'
    
    -- Contenido
    asunto VARCHAR(255),
    mensaje TEXT,
    
    -- Tracking
    enviado_en TIMESTAMP,
    error_mensaje TEXT,
    intentos INTEGER DEFAULT 0,
    
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_evn_conductor ON empresa_vehiculo_notificaciones(conductor_id);
CREATE INDEX IF NOT EXISTS idx_evn_estado ON empresa_vehiculo_notificaciones(estado);

-- 5. FUNCIÓN: Actualizar timestamp automáticamente
CREATE OR REPLACE FUNCTION update_empresa_tipos_vehiculo_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.actualizado_en = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_update_etv_timestamp ON empresa_tipos_vehiculo;
CREATE TRIGGER trigger_update_etv_timestamp
    BEFORE UPDATE ON empresa_tipos_vehiculo
    FOR EACH ROW
    EXECUTE FUNCTION update_empresa_tipos_vehiculo_timestamp();

-- 6. FUNCIÓN: Registrar historial de cambios
CREATE OR REPLACE FUNCTION log_empresa_tipo_vehiculo_change()
RETURNS TRIGGER AS $$
BEGIN
    -- Solo registrar si cambió el estado activo
    IF OLD.activo IS DISTINCT FROM NEW.activo THEN
        INSERT INTO empresa_tipos_vehiculo_historial (
            empresa_tipo_vehiculo_id,
            empresa_id,
            tipo_vehiculo_codigo,
            accion,
            estado_anterior,
            estado_nuevo,
            realizado_por,
            motivo
        ) VALUES (
            NEW.id,
            NEW.empresa_id,
            NEW.tipo_vehiculo_codigo,
            CASE WHEN NEW.activo THEN 'activado' ELSE 'desactivado' END,
            OLD.activo,
            NEW.activo,
            COALESCE(NEW.desactivado_por, NEW.activado_por),
            NEW.motivo_desactivacion
        );
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_log_etv_change ON empresa_tipos_vehiculo;
CREATE TRIGGER trigger_log_etv_change
    AFTER UPDATE ON empresa_tipos_vehiculo
    FOR EACH ROW
    EXECUTE FUNCTION log_empresa_tipo_vehiculo_change();

-- 7. FUNCIÓN: Contar conductores activos por tipo de vehículo
CREATE OR REPLACE FUNCTION update_empresa_tipo_vehiculo_conductores()
RETURNS TRIGGER AS $$
BEGIN
    -- Actualizar contador cuando cambia un conductor
    UPDATE empresa_tipos_vehiculo etv
    SET conductores_activos = (
        SELECT COUNT(*)
        FROM usuarios u
        INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE u.empresa_id = etv.empresa_id
        AND dc.tipo_vehiculo = etv.tipo_vehiculo_codigo
        AND dc.estado_verificacion = 'aprobado'
        AND u.es_activo = true
    )
    WHERE etv.empresa_id = COALESCE(NEW.empresa_id, OLD.empresa_id);
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 8. MIGRAR DATOS EXISTENTES
-- Crear registros para empresas que ya tienen tipos de vehículo
INSERT INTO empresa_tipos_vehiculo (empresa_id, tipo_vehiculo_codigo, activo, fecha_activacion)
SELECT 
    cp.empresa_id,
    cp.tipo_vehiculo,
    CASE WHEN cp.activo = 1 THEN true ELSE false END,
    COALESCE(cp.fecha_creacion, NOW())
FROM configuracion_precios cp
WHERE cp.empresa_id IS NOT NULL
AND cp.tipo_vehiculo IN (SELECT codigo FROM catalogo_tipos_vehiculo)
ON CONFLICT (empresa_id, tipo_vehiculo_codigo) DO UPDATE
SET activo = EXCLUDED.activo,
    actualizado_en = NOW();

-- 9. VISTA: Tipos de vehículo con información completa
CREATE OR REPLACE VIEW v_empresa_tipos_vehiculo AS
SELECT 
    etv.id,
    etv.empresa_id,
    e.nombre as empresa_nombre,
    etv.tipo_vehiculo_codigo,
    ctv.nombre as tipo_vehiculo_nombre,
    ctv.descripcion as tipo_vehiculo_descripcion,
    ctv.icono,
    etv.activo,
    etv.fecha_activacion,
    etv.fecha_desactivacion,
    etv.motivo_desactivacion,
    etv.conductores_activos,
    etv.viajes_completados,
    etv.creado_en,
    etv.actualizado_en,
    -- Datos del catálogo
    ctv.orden
FROM empresa_tipos_vehiculo etv
INNER JOIN empresas_transporte e ON etv.empresa_id = e.id
INNER JOIN catalogo_tipos_vehiculo ctv ON etv.tipo_vehiculo_codigo = ctv.codigo
ORDER BY e.nombre, ctv.orden;

-- 10. Comentarios de documentación
COMMENT ON TABLE empresa_tipos_vehiculo IS 'Tipos de vehículo habilitados por empresa con estado activo/inactivo';
COMMENT ON TABLE empresa_tipos_vehiculo_historial IS 'Historial de cambios de estado de tipos de vehículo';
COMMENT ON TABLE empresa_vehiculo_notificaciones IS 'Registro de notificaciones enviadas a conductores';
COMMENT ON TABLE catalogo_tipos_vehiculo IS 'Catálogo maestro de tipos de vehículo disponibles';

COMMENT ON COLUMN empresa_tipos_vehiculo.activo IS 'TRUE si el tipo de vehículo está habilitado para la empresa';
COMMENT ON COLUMN empresa_tipos_vehiculo.motivo_desactivacion IS 'Razón opcional por la cual se desactivó el tipo';
