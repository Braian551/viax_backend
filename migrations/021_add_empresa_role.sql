-- Migración: Agregar rol 'empresa' a la tabla usuarios
-- Fecha: 2025-12-26
-- Descripción: Modifica la columna tipo_usuario para incluir 'empresa'

USE pingo;

-- Modificar la columna tipo_usuario para agregar 'empresa' al ENUM
ALTER TABLE usuarios 
MODIFY COLUMN tipo_usuario ENUM('cliente', 'conductor', 'administrador', 'empresa') 
NOT NULL DEFAULT 'cliente';

-- Verificar el cambio
SHOW COLUMNS FROM usuarios LIKE 'tipo_usuario';
