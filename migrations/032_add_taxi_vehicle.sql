-- =====================================================
-- Migración 032: Agregar Taxi al Catálogo de Vehículos
-- =====================================================
-- Agrega el tipo de vehículo 'taxi' al catálogo para
-- permitir que empresas de taxi registren conductores
-- y tarifas específicas.
-- =====================================================

-- 1. Agregar Taxi al catálogo de tipos de vehículo
INSERT INTO catalogo_tipos_vehiculo (codigo, nombre, descripcion, icono, orden, activo) 
VALUES ('taxi', 'Taxi', 'Taxis tradicionales amarillos', 'local_taxi', 4, true)
ON CONFLICT (codigo) DO UPDATE SET 
    nombre = EXCLUDED.nombre,
    descripcion = EXCLUDED.descripcion,
    icono = EXCLUDED.icono,
    orden = EXCLUDED.orden;

-- 2. Crear configuración de precios global para Taxi (si no existe por empresa)
INSERT INTO configuracion_precios (
    tipo_vehiculo,
    tarifa_base,
    costo_por_km,
    costo_por_minuto,
    tarifa_minima,
    tarifa_maxima,
    recargo_hora_pico,
    recargo_nocturno,
    recargo_festivo,
    descuento_distancia_larga,
    umbral_km_descuento,
    comision_plataforma,
    distancia_minima,
    distancia_maxima,
    activo,
    notas
) VALUES (
    'taxi',
    7000.00,        -- Tarifa base
    3200.00,        -- Costo por km
    450.00,         -- Costo por minuto
    10000.00,       -- Tarifa mínima
    NULL,           -- Sin máximo
    22.00,          -- Recargo hora pico (22%)
    28.00,          -- Recargo nocturno (28%)
    35.00,          -- Recargo festivo (35%)
    12.00,          -- Descuento distancia larga (12%)
    12.00,          -- Umbral para descuento (12 km)
    0.00,           -- Comisión plataforma (0% - la empresa lo define)
    0.5,            -- Distancia mínima
    100.00,         -- Distancia máxima
    1,              -- Activo
    'Configuración global para servicio de taxi - Enero 2026'
)
ON CONFLICT DO NOTHING;

-- 3. Comentario de documentación
COMMENT ON COLUMN catalogo_tipos_vehiculo.codigo IS 'Código único del tipo: moto, auto, motocarro, taxi';

-- 4. Verificar inserción
SELECT codigo, nombre, descripcion, orden FROM catalogo_tipos_vehiculo ORDER BY orden;
