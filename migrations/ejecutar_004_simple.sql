-- Ejecutar Migración 004 SIMPLIFICADA
-- Ejecuta este archivo en MySQL Workbench o línea de comandos

USE pingo;

-- Agregar columna fecha_creacion a solicitudes_servicio
-- Ignorar si ya existe
ALTER TABLE solicitudes_servicio 
ADD COLUMN fecha_creacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER estado;

-- Copiar datos existentes
UPDATE solicitudes_servicio 
SET fecha_creacion = solicitado_en 
WHERE fecha_creacion IS NULL;

-- Agregar columna fecha_creacion a transacciones
-- Ignorar si ya existe
ALTER TABLE transacciones 
ADD COLUMN fecha_creacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER estado_pago;

-- Copiar datos existentes
UPDATE transacciones 
SET fecha_creacion = fecha_transaccion 
WHERE fecha_creacion IS NULL;

-- Verificación
SELECT 'VERIFICACIÓN DE COLUMNAS' as status;
SHOW COLUMNS FROM solicitudes_servicio LIKE 'fecha_creacion';
SHOW COLUMNS FROM transacciones LIKE 'fecha_creacion';
