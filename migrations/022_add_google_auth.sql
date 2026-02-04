-- Migración: Agregar soporte para autenticación con Google
-- Fecha: 2026-01-06
-- Descripción: Agrega columna google_id para vincular cuentas de Google

-- Agregar columna google_id a la tabla usuarios si no existe
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' 
                   AND column_name = 'google_id') THEN
        ALTER TABLE usuarios 
        ADD COLUMN google_id VARCHAR(255) UNIQUE;
        
        COMMENT ON COLUMN usuarios.google_id IS 'ID único del usuario en Google para OAuth';
    END IF;
END $$;

-- Crear índice para búsquedas rápidas por google_id
CREATE INDEX IF NOT EXISTS idx_usuarios_google_id ON usuarios(google_id) WHERE google_id IS NOT NULL;

-- Agregar columna apple_id para futura integración con Apple Sign-In
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' 
                   AND column_name = 'apple_id') THEN
        ALTER TABLE usuarios 
        ADD COLUMN apple_id VARCHAR(255) UNIQUE;
        
        COMMENT ON COLUMN usuarios.apple_id IS 'ID único del usuario en Apple para Sign-In with Apple';
    END IF;
END $$;

-- Crear índice para apple_id
CREATE INDEX IF NOT EXISTS idx_usuarios_apple_id ON usuarios(apple_id) WHERE apple_id IS NOT NULL;

-- Agregar columna auth_provider para saber el método de registro original
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' 
                   AND column_name = 'auth_provider') THEN
        ALTER TABLE usuarios 
        ADD COLUMN auth_provider VARCHAR(20) DEFAULT 'email';
        
        COMMENT ON COLUMN usuarios.auth_provider IS 'Método de autenticación original: email, google, apple';
    END IF;
END $$;

-- Verificar los cambios
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_name = 'usuarios'
AND column_name IN ('google_id', 'apple_id', 'auth_provider')
ORDER BY column_name;
