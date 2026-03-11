-- Configuracion fiscal del emisor principal para facturacion (empresa -> plataforma)

CREATE TABLE IF NOT EXISTS admin_emisor_fiscal (
    id BIGSERIAL PRIMARY KEY,
    admin_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    email_emisor VARCHAR(200) NOT NULL,
    nombre_legal VARCHAR(200) NOT NULL,
    tipo_documento VARCHAR(40) NOT NULL DEFAULT 'cedula_ciudadania',
    numero_documento VARCHAR(40) NOT NULL,
    regimen_fiscal VARCHAR(120) DEFAULT 'Responsable de IVA',
    direccion_fiscal VARCHAR(220),
    ciudad VARCHAR(120),
    departamento VARCHAR(120),
    pais VARCHAR(80) NOT NULL DEFAULT 'Colombia',
    telefono VARCHAR(40),
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (admin_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_emisor_email_unique ON admin_emisor_fiscal ((LOWER(email_emisor)));

-- Preconfiguracion del emisor principal requerida por negocio
UPDATE usuarios
SET nombre = 'Braian Andres',
    apellido = 'Oquendo Durango',
    fecha_actualizacion = CURRENT_TIMESTAMP
WHERE LOWER(email) = 'braianoquen@gmail.com'
  AND tipo_usuario IN ('admin', 'administrador');

INSERT INTO admin_emisor_fiscal (
    admin_id,
    email_emisor,
    nombre_legal,
    tipo_documento,
    numero_documento,
    regimen_fiscal,
    ciudad,
    pais,
    actualizado_en
)
SELECT
    u.id,
    'braianoquen@gmail.com',
    'Braian Andres Oquendo Durango',
    'cedula_ciudadania',
    '1023526011',
    'Responsable de IVA',
    'Bogota D.C.',
    'Colombia',
    CURRENT_TIMESTAMP
FROM usuarios u
WHERE LOWER(u.email) = 'braianoquen@gmail.com'
  AND u.tipo_usuario IN ('admin', 'administrador')
ON CONFLICT (admin_id) DO UPDATE SET
    email_emisor = EXCLUDED.email_emisor,
    nombre_legal = EXCLUDED.nombre_legal,
    tipo_documento = EXCLUDED.tipo_documento,
    numero_documento = EXCLUDED.numero_documento,
    regimen_fiscal = EXCLUDED.regimen_fiscal,
    ciudad = EXCLUDED.ciudad,
    pais = EXCLUDED.pais,
    actualizado_en = CURRENT_TIMESTAMP;
