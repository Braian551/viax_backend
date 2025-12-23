-- Script para ejecutar la migración 003 de manera segura
-- Este script debe ejecutarse en tu base de datos MySQL

-- 1. Primero, haz un backup de la tabla usuarios
CREATE TABLE IF NOT EXISTS usuarios_backup_20251023 AS SELECT * FROM usuarios;

-- Verificar que el backup se creó correctamente
SELECT 
    COUNT(*) as total_usuarios_backup,
    'Backup creado exitosamente' as mensaje
FROM usuarios_backup_20251023;

-- 2. Ejecutar la migración 003
SOURCE 003_fix_usuarios_columns.sql;

-- 3. Verificar que todo esté correcto
SELECT 
    'Verificación final' as paso,
    COUNT(*) as total_usuarios
FROM usuarios;

-- Mostrar estructura final de la tabla
DESCRIBE usuarios;

SELECT 'Migración completada. Si hay algún problema, puedes restaurar desde usuarios_backup_20251023' as nota_final;
