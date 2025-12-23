-- Migración para arreglar la columna id con autoincremento en PostgreSQL
-- Esto es necesario porque la migración desde MySQL no incluyó las secuencias

-- 1. Primero, crear la secuencia para solicitudes_servicio
CREATE SEQUENCE IF NOT EXISTS solicitudes_servicio_id_seq;

-- 2. Obtener el valor máximo actual de id y establecer la secuencia
SELECT setval('solicitudes_servicio_id_seq', COALESCE((SELECT MAX(id) FROM solicitudes_servicio), 0) + 1, false);

-- 3. Establecer el valor por defecto de la columna id
ALTER TABLE solicitudes_servicio ALTER COLUMN id SET DEFAULT nextval('solicitudes_servicio_id_seq');

-- 4. Vincular la secuencia a la columna
ALTER SEQUENCE solicitudes_servicio_id_seq OWNED BY solicitudes_servicio.id;

-- Hacer lo mismo para otras tablas que puedan tener el mismo problema

-- asignaciones_conductor
CREATE SEQUENCE IF NOT EXISTS asignaciones_conductor_id_seq;
SELECT setval('asignaciones_conductor_id_seq', COALESCE((SELECT MAX(id) FROM asignaciones_conductor), 0) + 1, false);
ALTER TABLE asignaciones_conductor ALTER COLUMN id SET DEFAULT nextval('asignaciones_conductor_id_seq');
ALTER SEQUENCE asignaciones_conductor_id_seq OWNED BY asignaciones_conductor.id;

-- calificaciones
CREATE SEQUENCE IF NOT EXISTS calificaciones_id_seq;
SELECT setval('calificaciones_id_seq', COALESCE((SELECT MAX(id) FROM calificaciones), 0) + 1, false);
ALTER TABLE calificaciones ALTER COLUMN id SET DEFAULT nextval('calificaciones_id_seq');
ALTER SEQUENCE calificaciones_id_seq OWNED BY calificaciones.id;

-- configuracion_precios
CREATE SEQUENCE IF NOT EXISTS configuracion_precios_id_seq;
SELECT setval('configuracion_precios_id_seq', COALESCE((SELECT MAX(id) FROM configuracion_precios), 0) + 1, false);
ALTER TABLE configuracion_precios ALTER COLUMN id SET DEFAULT nextval('configuracion_precios_id_seq');
ALTER SEQUENCE configuracion_precios_id_seq OWNED BY configuracion_precios.id;

-- configuraciones_app
CREATE SEQUENCE IF NOT EXISTS configuraciones_app_id_seq;
SELECT setval('configuraciones_app_id_seq', COALESCE((SELECT MAX(id) FROM configuraciones_app), 0) + 1, false);
ALTER TABLE configuraciones_app ALTER COLUMN id SET DEFAULT nextval('configuraciones_app_id_seq');
ALTER SEQUENCE configuraciones_app_id_seq OWNED BY configuraciones_app.id;

-- detalles_conductor
CREATE SEQUENCE IF NOT EXISTS detalles_conductor_id_seq;
SELECT setval('detalles_conductor_id_seq', COALESCE((SELECT MAX(id) FROM detalles_conductor), 0) + 1, false);
ALTER TABLE detalles_conductor ALTER COLUMN id SET DEFAULT nextval('detalles_conductor_id_seq');
ALTER SEQUENCE detalles_conductor_id_seq OWNED BY detalles_conductor.id;

-- transacciones
CREATE SEQUENCE IF NOT EXISTS transacciones_id_seq;
SELECT setval('transacciones_id_seq', COALESCE((SELECT MAX(id) FROM transacciones), 0) + 1, false);
ALTER TABLE transacciones ALTER COLUMN id SET DEFAULT nextval('transacciones_id_seq');
ALTER SEQUENCE transacciones_id_seq OWNED BY transacciones.id;

-- ubicaciones_usuario
CREATE SEQUENCE IF NOT EXISTS ubicaciones_usuario_id_seq;
SELECT setval('ubicaciones_usuario_id_seq', COALESCE((SELECT MAX(id) FROM ubicaciones_usuario), 0) + 1, false);
ALTER TABLE ubicaciones_usuario ALTER COLUMN id SET DEFAULT nextval('ubicaciones_usuario_id_seq');
ALTER SEQUENCE ubicaciones_usuario_id_seq OWNED BY ubicaciones_usuario.id;

-- usuarios
CREATE SEQUENCE IF NOT EXISTS usuarios_id_seq;
SELECT setval('usuarios_id_seq', COALESCE((SELECT MAX(id) FROM usuarios), 0) + 1, false);
ALTER TABLE usuarios ALTER COLUMN id SET DEFAULT nextval('usuarios_id_seq');
ALTER SEQUENCE usuarios_id_seq OWNED BY usuarios.id;

-- paradas_solicitud (si existe)
DO $$
BEGIN
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'paradas_solicitud') THEN
        CREATE SEQUENCE IF NOT EXISTS paradas_solicitud_id_seq;
        PERFORM setval('paradas_solicitud_id_seq', COALESCE((SELECT MAX(id) FROM paradas_solicitud), 0) + 1, false);
        ALTER TABLE paradas_solicitud ALTER COLUMN id SET DEFAULT nextval('paradas_solicitud_id_seq');
        ALTER SEQUENCE paradas_solicitud_id_seq OWNED BY paradas_solicitud.id;
    END IF;
END $$;

-- cache_direcciones
CREATE SEQUENCE IF NOT EXISTS cache_direcciones_id_seq;
SELECT setval('cache_direcciones_id_seq', COALESCE((SELECT MAX(id) FROM cache_direcciones), 0) + 1, false);
ALTER TABLE cache_direcciones ALTER COLUMN id SET DEFAULT nextval('cache_direcciones_id_seq');
ALTER SEQUENCE cache_direcciones_id_seq OWNED BY cache_direcciones.id;

-- cache_geocodificacion
CREATE SEQUENCE IF NOT EXISTS cache_geocodificacion_id_seq;
SELECT setval('cache_geocodificacion_id_seq', COALESCE((SELECT MAX(id) FROM cache_geocodificacion), 0) + 1, false);
ALTER TABLE cache_geocodificacion ALTER COLUMN id SET DEFAULT nextval('cache_geocodificacion_id_seq');
ALTER SEQUENCE cache_geocodificacion_id_seq OWNED BY cache_geocodificacion.id;
