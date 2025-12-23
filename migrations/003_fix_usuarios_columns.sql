-- Migración: Corregir nombres de columnas en tabla usuarios
-- Fecha: 2025-10-23
-- Descripción: Renombra columnas para consistencia con el backend
--   activo -> es_activo
--   verificado -> es_verificado
--   url_imagen_perfil -> foto_perfil
--   creado_en -> fecha_registro
--   actualizado_en -> fecha_actualizacion

USE pingo;

-- Verificar la estructura actual
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'pingo' 
AND TABLE_NAME = 'usuarios'
AND COLUMN_NAME IN ('activo', 'verificado', 'es_activo', 'es_verificado', 
                    'url_imagen_perfil', 'foto_perfil', 
                    'creado_en', 'fecha_registro',
                    'actualizado_en', 'fecha_actualizacion');

-- Renombrar columna 'activo' a 'es_activo' si existe
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'pingo' 
    AND TABLE_NAME = 'usuarios' 
    AND COLUMN_NAME = 'activo'
);

SET @sql_activo = IF(@column_exists > 0,
    'ALTER TABLE usuarios CHANGE COLUMN activo es_activo TINYINT(1) DEFAULT 1 COMMENT ''Indica si el usuario está activo en el sistema'';',
    'SELECT ''La columna activo no existe o ya fue renombrada'' AS info;'
);

PREPARE stmt_activo FROM @sql_activo;
EXECUTE stmt_activo;
DEALLOCATE PREPARE stmt_activo;

-- Renombrar columna 'verificado' a 'es_verificado' si existe
SET @column_exists_verif = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'pingo' 
    AND TABLE_NAME = 'usuarios' 
    AND COLUMN_NAME = 'verificado'
);

SET @sql_verificado = IF(@column_exists_verif > 0,
    'ALTER TABLE usuarios CHANGE COLUMN verificado es_verificado TINYINT(1) DEFAULT 0 COMMENT ''Indica si el usuario verificó su email/teléfono'';',
    'SELECT ''La columna verificado no existe o ya fue renombrada'' AS info;'
);

PREPARE stmt_verificado FROM @sql_verificado;
EXECUTE stmt_verificado;
DEALLOCATE PREPARE stmt_verificado;

-- Renombrar columna 'url_imagen_perfil' a 'foto_perfil' si existe
SET @column_exists_foto = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'pingo' 
    AND TABLE_NAME = 'usuarios' 
    AND COLUMN_NAME = 'url_imagen_perfil'
);

SET @sql_foto = IF(@column_exists_foto > 0,
    'ALTER TABLE usuarios CHANGE COLUMN url_imagen_perfil foto_perfil VARCHAR(500) DEFAULT NULL COMMENT ''URL de la foto de perfil del usuario'';',
    'SELECT ''La columna url_imagen_perfil no existe o ya fue renombrada'' AS info;'
);

PREPARE stmt_foto FROM @sql_foto;
EXECUTE stmt_foto;
DEALLOCATE PREPARE stmt_foto;

-- Renombrar columna 'creado_en' a 'fecha_registro' si existe
SET @column_exists_creado = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'pingo' 
    AND TABLE_NAME = 'usuarios' 
    AND COLUMN_NAME = 'creado_en'
);

SET @sql_creado = IF(@column_exists_creado > 0,
    'ALTER TABLE usuarios CHANGE COLUMN creado_en fecha_registro TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT ''Fecha de registro del usuario'';',
    'SELECT ''La columna creado_en no existe o ya fue renombrada'' AS info;'
);

PREPARE stmt_creado FROM @sql_creado;
EXECUTE stmt_creado;
DEALLOCATE PREPARE stmt_creado;

-- Renombrar columna 'actualizado_en' a 'fecha_actualizacion' si existe
SET @column_exists_actualizado = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'pingo' 
    AND TABLE_NAME = 'usuarios' 
    AND COLUMN_NAME = 'actualizado_en'
);

SET @sql_actualizado = IF(@column_exists_actualizado > 0,
    'ALTER TABLE usuarios CHANGE COLUMN actualizado_en fecha_actualizacion TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT ''Fecha de última actualización'';',
    'SELECT ''La columna actualizado_en no existe o ya fue renombrada'' AS info;'
);

PREPARE stmt_actualizado FROM @sql_actualizado;
EXECUTE stmt_actualizado;
DEALLOCATE PREPARE stmt_actualizado;

-- Verificar los cambios
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'pingo' 
AND TABLE_NAME = 'usuarios'
AND COLUMN_NAME IN ('es_activo', 'es_verificado', 'foto_perfil', 'fecha_registro', 'fecha_actualizacion')
ORDER BY COLUMN_NAME;

-- Confirmar que las columnas antiguas no existen
SELECT 
    CASE 
        WHEN COUNT(*) = 0 THEN 'ÉXITO: Columnas antiguas eliminadas correctamente'
        ELSE 'ADVERTENCIA: Aún existen columnas antiguas'
    END AS resultado
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'pingo' 
AND TABLE_NAME = 'usuarios'
AND COLUMN_NAME IN ('activo', 'verificado', 'url_imagen_perfil', 'creado_en', 'actualizado_en');

-- Mostrar resumen de usuarios
SELECT 
    COUNT(*) as total_usuarios,
    SUM(CASE WHEN es_activo = 1 THEN 1 ELSE 0 END) as usuarios_activos,
    SUM(CASE WHEN es_verificado = 1 THEN 1 ELSE 0 END) as usuarios_verificados,
    SUM(CASE WHEN tipo_usuario = 'administrador' THEN 1 ELSE 0 END) as administradores,
    SUM(CASE WHEN tipo_usuario = 'conductor' THEN 1 ELSE 0 END) as conductores,
    SUM(CASE WHEN tipo_usuario = 'cliente' THEN 1 ELSE 0 END) as clientes
FROM usuarios;

SELECT '=== Migración 003 completada exitosamente ===' AS mensaje;
SELECT 'Todas las columnas han sido renombradas correctamente' AS detalle;
