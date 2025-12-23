-- Migración 003 Simplificada - Solo ALTER TABLE
-- Fecha: 2025-10-23

USE pingo;

-- Crear backup
CREATE TABLE IF NOT EXISTS usuarios_backup_20251023 AS SELECT * FROM usuarios;

-- Renombrar columnas
ALTER TABLE usuarios CHANGE COLUMN activo es_activo TINYINT(1) DEFAULT 1;
ALTER TABLE usuarios CHANGE COLUMN verificado es_verificado TINYINT(1) DEFAULT 0;
ALTER TABLE usuarios CHANGE COLUMN url_imagen_perfil foto_perfil VARCHAR(500) DEFAULT NULL;
ALTER TABLE usuarios CHANGE COLUMN creado_en fecha_registro TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE usuarios CHANGE COLUMN actualizado_en fecha_actualizacion TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;
