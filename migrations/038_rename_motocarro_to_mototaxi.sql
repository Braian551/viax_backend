-- Migración 038: Renombrar tipo de vehículo de 'motocarro' a 'mototaxi'
-- Objetivo: actualizar datos históricos y constraints sin romper compatibilidad operativa.

BEGIN;

-- 1) Normalizar catálogo maestro
UPDATE catalogo_tipos_vehiculo
SET codigo = 'mototaxi',
    nombre = 'Mototaxi',
    descripcion = REPLACE(descripcion, 'Motocarro', 'Mototaxi')
WHERE codigo = 'motocarro';

-- 2) Actualizar referencias directas por código
UPDATE empresa_tipos_vehiculo
SET tipo_vehiculo_codigo = 'mototaxi'
WHERE tipo_vehiculo_codigo = 'motocarro';

UPDATE empresa_tipos_vehiculo_historial
SET tipo_vehiculo_codigo = 'mototaxi'
WHERE tipo_vehiculo_codigo = 'motocarro';

UPDATE empresa_vehiculo_notificaciones
SET tipo_vehiculo_codigo = 'mototaxi'
WHERE tipo_vehiculo_codigo = 'motocarro';

UPDATE configuracion_precios
SET tipo_vehiculo = 'mototaxi'
WHERE tipo_vehiculo = 'motocarro';

UPDATE solicitudes_servicio
SET tipo_vehiculo = 'mototaxi'
WHERE tipo_vehiculo = 'motocarro';

UPDATE detalles_conductor
SET tipo_vehiculo = 'mototaxi'
WHERE tipo_vehiculo = 'motocarro';

-- 3) Actualizar arrays de tipos (si existen)
UPDATE empresas_transporte
SET tipos_vehiculo = array_replace(tipos_vehiculo, 'motocarro', 'mototaxi')
WHERE tipos_vehiculo IS NOT NULL
  AND 'motocarro' = ANY(tipos_vehiculo);

UPDATE empresas_configuracion
SET tipos_vehiculo = array_replace(tipos_vehiculo, 'motocarro', 'mototaxi')
WHERE tipos_vehiculo IS NOT NULL
  AND 'motocarro' = ANY(tipos_vehiculo);

-- 4) Ajustar constraints de check (si existen)
ALTER TABLE configuracion_precios DROP CONSTRAINT IF EXISTS check_tipo_vehiculo;
ALTER TABLE configuracion_precios
ADD CONSTRAINT check_tipo_vehiculo
CHECK (tipo_vehiculo IN ('auto', 'moto', 'mototaxi', 'taxi', 'carro'));

ALTER TABLE solicitudes_servicio DROP CONSTRAINT IF EXISTS check_tipo_vehiculo_solicitud;
ALTER TABLE solicitudes_servicio
ADD CONSTRAINT check_tipo_vehiculo_solicitud
CHECK (tipo_vehiculo IS NULL OR tipo_vehiculo IN ('moto', 'auto', 'mototaxi', 'taxi', 'carro'));

ALTER TABLE detalles_conductor DROP CONSTRAINT IF EXISTS check_tipo_vehiculo_conductor;
ALTER TABLE detalles_conductor
ADD CONSTRAINT check_tipo_vehiculo_conductor
CHECK (tipo_vehiculo IN ('auto', 'moto', 'mototaxi', 'taxi', 'carro'));

COMMIT;
