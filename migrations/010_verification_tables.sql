-- Tabla para historial y estado de documentos
-- Corrección: Referencia a tabla 'usuarios' para conductor_id (pues conductores son usuarios con rol conductor)
CREATE TABLE IF NOT EXISTS documentos_verificacion (
    id SERIAL PRIMARY KEY,
    conductor_id INT NOT NULL,
    tipo_documento VARCHAR(50) NOT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    estado VARCHAR(20) DEFAULT 'pendiente',
    comentario_rechazo TEXT,
    fecha_subida TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_verificacion TIMESTAMP NULL,
    FOREIGN KEY (conductor_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- Columna para estado biométrico
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='detalles_conductor' AND column_name='estado_biometrico') THEN
        ALTER TABLE detalles_conductor ADD COLUMN estado_biometrico VARCHAR(20) DEFAULT 'pendiente';
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_docs_tipo_estado ON documentos_verificacion(tipo_documento, estado);
