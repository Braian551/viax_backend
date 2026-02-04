-- Migración: Actualizar CHECK constraint para incluir 'empresa' en tipo_usuario
-- Fecha: 2025-12-27
-- Descripción: El constraint actual solo permite 'cliente', 'conductor', 'administrador'.
--              Esta migración agrega 'empresa' como valor válido.

-- Paso 1: Eliminar el CHECK constraint existente
ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_tipo_usuario_check;

-- Paso 2: Crear el nuevo CHECK constraint con 'empresa' incluido
ALTER TABLE usuarios ADD CONSTRAINT usuarios_tipo_usuario_check 
    CHECK (tipo_usuario IN ('cliente', 'conductor', 'administrador', 'empresa'));

-- Verificar el cambio
SELECT constraint_name, check_clause
FROM information_schema.check_constraints
WHERE constraint_name = 'usuarios_tipo_usuario_check';
