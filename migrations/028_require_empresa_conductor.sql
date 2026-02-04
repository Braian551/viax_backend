-- =====================================================
-- Migración 028: Conductores Obligatoriamente Vinculados a Empresa
-- =====================================================
-- Este cambio elimina la posibilidad de conductores independientes.
-- Todos los conductores DEBEN estar vinculados a una empresa de transporte.
-- =====================================================

-- 1. Agregar columna estado_vinculacion si no existe
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' 
                   AND column_name = 'estado_vinculacion') THEN
        ALTER TABLE usuarios 
        ADD COLUMN estado_vinculacion VARCHAR(50) DEFAULT 'activo';
        
        CREATE INDEX IF NOT EXISTS idx_usuarios_estado_vinculacion ON usuarios(estado_vinculacion);
    END IF;
END $$;

-- 2. Primero, verificar si hay conductores sin empresa
DO $$
DECLARE
    conductores_sin_empresa INTEGER;
BEGIN
    SELECT COUNT(*) INTO conductores_sin_empresa
    FROM usuarios 
    WHERE tipo_usuario = 'conductor' 
    AND empresa_id IS NULL;
    
    IF conductores_sin_empresa > 0 THEN
        RAISE NOTICE 'ADVERTENCIA: Hay % conductores sin empresa vinculada. Se suspenderán hasta que se vinculen.', conductores_sin_empresa;
    END IF;
END $$;

-- 2. Crear tabla de solicitudes de vinculación de conductores a empresas
CREATE TABLE IF NOT EXISTS solicitudes_vinculacion_conductor (
    id BIGSERIAL PRIMARY KEY,
    
    -- Conductor que solicita vinculación
    conductor_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    
    -- Empresa a la que solicita vincularse
    empresa_id BIGINT NOT NULL REFERENCES empresas_transporte(id) ON DELETE CASCADE,
    
    -- Estado de la solicitud: 'pendiente', 'aprobada', 'rechazada'
    estado VARCHAR(50) DEFAULT 'pendiente',
    
    -- Mensaje opcional del conductor
    mensaje_conductor TEXT,
    
    -- Respuesta de la empresa (si rechaza, puede dar razón)
    respuesta_empresa TEXT,
    
    -- Usuario que procesó la solicitud (admin de empresa o sistema)
    procesado_por BIGINT REFERENCES usuarios(id),
    
    -- Timestamps
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    procesado_en TIMESTAMP,
    
    -- Evitar solicitudes duplicadas pendientes
    CONSTRAINT ux_solicitud_pendiente UNIQUE (conductor_id, empresa_id, estado)
);

-- Índices para búsquedas eficientes
CREATE INDEX IF NOT EXISTS idx_solicitud_vinc_conductor ON solicitudes_vinculacion_conductor(conductor_id);
CREATE INDEX IF NOT EXISTS idx_solicitud_vinc_empresa ON solicitudes_vinculacion_conductor(empresa_id);
CREATE INDEX IF NOT EXISTS idx_solicitud_vinc_estado ON solicitudes_vinculacion_conductor(estado);
CREATE INDEX IF NOT EXISTS idx_solicitud_vinc_creado ON solicitudes_vinculacion_conductor(creado_en);

-- 3. Suspender conductores sin empresa (no pueden operar hasta vincularse)
-- Usamos estado_vinculacion en lugar de estado
UPDATE usuarios 
SET 
    estado_vinculacion = 'pendiente_empresa',
    es_activo = 0
WHERE tipo_usuario = 'conductor' 
AND empresa_id IS NULL
AND (estado_vinculacion IS NULL OR estado_vinculacion != 'pendiente_empresa');

-- 4. Modificar la columna empresa_id para que sea NOT NULL para nuevos conductores
-- No podemos hacer NOT NULL directo porque hay registros existentes
-- En su lugar, usaremos un CHECK constraint con validación de tipo_usuario

-- Primero eliminar el constraint si existe
ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS chk_conductor_empresa_required;

-- Crear constraint que requiere empresa_id para conductores
-- Nota: Los conductores existentes sin empresa quedan con estado_vinculacion 'pendiente_empresa'
ALTER TABLE usuarios 
ADD CONSTRAINT chk_conductor_empresa_required 
CHECK (
    -- Si es conductor, debe tener empresa_id O estar en estado_vinculacion pendiente_empresa
    (tipo_usuario != 'conductor') 
    OR (empresa_id IS NOT NULL) 
    OR (estado_vinculacion = 'pendiente_empresa')
);

-- 5. Crear vista para conductores pendientes de vinculación
DROP VIEW IF EXISTS conductores_pendientes_vinculacion;
CREATE VIEW conductores_pendientes_vinculacion AS
SELECT 
    u.id,
    u.nombre,
    u.apellido,
    u.email,
    u.telefono,
    u.fecha_registro AS creado_en,
    u.estado_vinculacion,
    dc.vehiculo_tipo,
    dc.vehiculo_marca,
    dc.vehiculo_modelo,
    dc.vehiculo_placa,
    sv.empresa_id AS empresa_solicitada_id,
    et.nombre AS empresa_solicitada_nombre,
    sv.estado AS estado_solicitud,
    sv.creado_en AS fecha_solicitud
FROM usuarios u
LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
LEFT JOIN solicitudes_vinculacion_conductor sv ON u.id = sv.conductor_id AND sv.estado = 'pendiente'
LEFT JOIN empresas_transporte et ON sv.empresa_id = et.id
WHERE u.tipo_usuario = 'conductor' 
AND u.empresa_id IS NULL;

-- 6. Crear función para aprobar vinculación de conductor
CREATE OR REPLACE FUNCTION aprobar_vinculacion_conductor(
    p_solicitud_id BIGINT,
    p_aprobado_por BIGINT
) RETURNS JSON AS $$
DECLARE
    v_conductor_id BIGINT;
    v_empresa_id BIGINT;
    v_resultado JSON;
BEGIN
    -- Obtener datos de la solicitud
    SELECT conductor_id, empresa_id INTO v_conductor_id, v_empresa_id
    FROM solicitudes_vinculacion_conductor
    WHERE id = p_solicitud_id AND estado = 'pendiente';
    
    IF v_conductor_id IS NULL THEN
        RETURN json_build_object('success', false, 'message', 'Solicitud no encontrada o ya procesada');
    END IF;
    
    -- Actualizar solicitud
    UPDATE solicitudes_vinculacion_conductor
    SET estado = 'aprobada', procesado_por = p_aprobado_por, procesado_en = CURRENT_TIMESTAMP
    WHERE id = p_solicitud_id;
    
    -- Vincular conductor a empresa y reactivar
    UPDATE usuarios
    SET empresa_id = v_empresa_id, 
        estado_vinculacion = 'vinculado',
        es_activo = 1, 
        fecha_actualizacion = CURRENT_TIMESTAMP
    WHERE id = v_conductor_id;
    
    -- Actualizar contador de conductores en empresa
    UPDATE empresas_transporte
    SET total_conductores = total_conductores + 1, actualizado_en = CURRENT_TIMESTAMP
    WHERE id = v_empresa_id;
    
    -- Rechazar otras solicitudes pendientes del mismo conductor
    UPDATE solicitudes_vinculacion_conductor
    SET estado = 'rechazada', 
        respuesta_empresa = 'Conductor vinculado a otra empresa',
        procesado_en = CURRENT_TIMESTAMP
    WHERE conductor_id = v_conductor_id AND estado = 'pendiente' AND id != p_solicitud_id;
    
    RETURN json_build_object(
        'success', true, 
        'message', 'Conductor vinculado exitosamente',
        'conductor_id', v_conductor_id,
        'empresa_id', v_empresa_id
    );
END;
$$ LANGUAGE plpgsql;

-- 7. Crear función para rechazar vinculación
CREATE OR REPLACE FUNCTION rechazar_vinculacion_conductor(
    p_solicitud_id BIGINT,
    p_rechazado_por BIGINT,
    p_razon TEXT DEFAULT 'Solicitud rechazada por la empresa'
) RETURNS JSON AS $$
BEGIN
    UPDATE solicitudes_vinculacion_conductor
    SET estado = 'rechazada', 
        procesado_por = p_rechazado_por, 
        procesado_en = CURRENT_TIMESTAMP,
        respuesta_empresa = p_razon
    WHERE id = p_solicitud_id AND estado = 'pendiente';
    
    IF NOT FOUND THEN
        RETURN json_build_object('success', false, 'message', 'Solicitud no encontrada o ya procesada');
    END IF;
    
    RETURN json_build_object('success', true, 'message', 'Solicitud rechazada');
END;
$$ LANGUAGE plpgsql;

-- 8. Trigger para validar que nuevos conductores tengan solicitud de empresa
CREATE OR REPLACE FUNCTION validar_conductor_nueva_empresa()
RETURNS TRIGGER AS $$
BEGIN
    -- Si se está registrando como conductor sin empresa
    IF NEW.tipo_usuario = 'conductor' AND NEW.empresa_id IS NULL THEN
        -- Permitir pero forzar estado pendiente_empresa
        NEW.estado_vinculacion := 'pendiente_empresa';
        NEW.es_activo := 0;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_validar_conductor_empresa ON usuarios;
CREATE TRIGGER trigger_validar_conductor_empresa
    BEFORE INSERT OR UPDATE ON usuarios
    FOR EACH ROW
    WHEN (NEW.tipo_usuario = 'conductor')
    EXECUTE FUNCTION validar_conductor_nueva_empresa();

-- 9. Comentarios descriptivos
COMMENT ON TABLE solicitudes_vinculacion_conductor IS 'Solicitudes de conductores para vincularse a empresas de transporte';
COMMENT ON CONSTRAINT chk_conductor_empresa_required ON usuarios IS 'Conductores deben tener empresa_id o estar en estado_vinculacion pendiente_empresa';
COMMENT ON VIEW conductores_pendientes_vinculacion IS 'Vista de conductores que necesitan vincularse a una empresa';

-- 10. Log de migración
INSERT INTO logs_auditoria (usuario_id, accion, entidad, descripcion, fecha_creacion)
VALUES (
    1, 
    'migracion', 
    'sistema', 
    'Migración 028: Conductores obligatoriamente vinculados a empresa. Eliminada opción independiente.', 
    CURRENT_TIMESTAMP
);

-- =====================================================
-- RESUMEN DE CAMBIOS:
-- 1. Conductores sin empresa pasan a estado 'pendiente_empresa'
-- 2. Nueva tabla solicitudes_vinculacion_conductor para gestionar solicitudes
-- 3. Constraint que obliga empresa_id para conductores activos
-- 4. Vista para ver conductores pendientes de vinculación
-- 5. Funciones SQL para aprobar/rechazar vinculaciones
-- 6. Trigger que fuerza estado pendiente_empresa si no hay empresa
-- =====================================================
