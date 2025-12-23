-- ============================================================================
-- Migración: Asegurar tabla de calificaciones completa
-- Fecha: 2024-12-21
-- ============================================================================

-- Crear tabla de calificaciones si no existe
CREATE TABLE IF NOT EXISTS calificaciones (
    id SERIAL PRIMARY KEY,
    solicitud_id INT NOT NULL,
    calificador_id INT NOT NULL,
    calificado_id INT NOT NULL,
    calificacion INT NOT NULL CHECK (calificacion >= 1 AND calificacion <= 5),
    tipo_calificador VARCHAR(20) NOT NULL CHECK (tipo_calificador IN ('cliente', 'conductor')),
    comentario TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Referencias
    CONSTRAINT fk_calificacion_solicitud 
        FOREIGN KEY (solicitud_id) REFERENCES solicitudes_servicio(id) ON DELETE CASCADE,
    CONSTRAINT fk_calificacion_calificador 
        FOREIGN KEY (calificador_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_calificacion_calificado 
        FOREIGN KEY (calificado_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    
    -- Índices únicos para evitar calificaciones duplicadas
    CONSTRAINT unique_calificacion_por_usuario 
        UNIQUE (solicitud_id, calificador_id)
);

-- Agregar campos faltantes a solicitudes_servicio para tracking de pago
ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS pago_confirmado BOOLEAN DEFAULT FALSE;

ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS pago_confirmado_en TIMESTAMP;

ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS metodo_pago_usado VARCHAR(50);

-- Agregar campos faltantes a detalles_conductor
ALTER TABLE detalles_conductor 
ADD COLUMN IF NOT EXISTS total_calificaciones INT DEFAULT 0;

ALTER TABLE detalles_conductor 
ADD COLUMN IF NOT EXISTS ganancias_totales NUMERIC(12,2) DEFAULT 0;

-- Agregar campo de calificación promedio a usuarios (para clientes)
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS calificacion_promedio NUMERIC(3,2) DEFAULT 5.0;

-- Crear índices para mejor rendimiento
CREATE INDEX IF NOT EXISTS idx_calificaciones_solicitud ON calificaciones(solicitud_id);
CREATE INDEX IF NOT EXISTS idx_calificaciones_calificado ON calificaciones(calificado_id);
CREATE INDEX IF NOT EXISTS idx_calificaciones_tipo ON calificaciones(tipo_calificador);

-- ============================================================================
-- Tabla de pagos (opcional, para historial detallado)
-- ============================================================================
CREATE TABLE IF NOT EXISTS pagos_viaje (
    id SERIAL PRIMARY KEY,
    solicitud_id INT UNIQUE NOT NULL,
    conductor_id INT NOT NULL,
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

-- ============================================================================
-- Comentarios
-- ============================================================================
COMMENT ON TABLE calificaciones IS 'Calificaciones de viajes entre conductores y clientes';
COMMENT ON COLUMN calificaciones.tipo_calificador IS 'cliente = cliente califica a conductor, conductor = conductor califica a cliente';
COMMENT ON TABLE pagos_viaje IS 'Historial de pagos de viajes';
