-- =====================================================
-- Migración: Agregar tipos de vehículo taxi y carro
-- =====================================================
-- Esta migración agrega los tipos taxi y carro al catálogo
-- y actualiza las referencias existentes de 'auto' a 'carro'
-- SEGURO: Usa ON CONFLICT para evitar duplicados
-- =====================================================

-- 0. Verificar estado actual del catálogo
SELECT '=== ESTADO ACTUAL DEL CATÁLOGO ===' as info;
SELECT codigo, nombre, activo, orden FROM catalogo_tipos_vehiculo ORDER BY orden;

-- 1. Agregar nuevos tipos al catálogo
INSERT INTO catalogo_tipos_vehiculo (codigo, nombre, descripcion, icono, orden) VALUES
    ('taxi', 'Taxi', 'Taxis y transporte público', 'local_taxi', 3),
    ('carro', 'Carro', 'Automóviles particulares', 'directions_car', 4)
ON CONFLICT (codigo) DO NOTHING;

-- 2. Actualizar el orden de los tipos existentes para consistencia
UPDATE catalogo_tipos_vehiculo SET orden = 1 WHERE codigo = 'moto';
UPDATE catalogo_tipos_vehiculo SET orden = 2 WHERE codigo = 'motocarro';
UPDATE catalogo_tipos_vehiculo SET orden = 3 WHERE codigo = 'taxi';
UPDATE catalogo_tipos_vehiculo SET orden = 4 WHERE codigo = 'carro';

-- 3. Si existe 'auto' en el catálogo, migrar a 'carro'
-- Primero actualizar referencias en empresa_tipos_vehiculo
UPDATE empresa_tipos_vehiculo 
SET tipo_vehiculo_codigo = 'carro' 
WHERE tipo_vehiculo_codigo = 'auto'
AND NOT EXISTS (
    SELECT 1 FROM empresa_tipos_vehiculo e2 
    WHERE e2.empresa_id = empresa_tipos_vehiculo.empresa_id 
    AND e2.tipo_vehiculo_codigo = 'carro'
);

-- Actualizar referencias en configuracion_precios
UPDATE configuracion_precios 
SET tipo_vehiculo = 'carro' 
WHERE tipo_vehiculo = 'auto'
AND NOT EXISTS (
    SELECT 1 FROM configuracion_precios cp2 
    WHERE cp2.empresa_id = configuracion_precios.empresa_id 
    AND cp2.tipo_vehiculo = 'carro'
);

-- 4. Opcionalmente desactivar 'auto' del catálogo (mantener por compatibilidad)
UPDATE catalogo_tipos_vehiculo SET activo = false WHERE codigo = 'auto';

-- 5. Comentarios de documentación
COMMENT ON COLUMN catalogo_tipos_vehiculo.codigo IS 'Tipos válidos: moto, motocarro, taxi, carro';

-- Verificar resultado
SELECT codigo, nombre, descripcion, orden, activo FROM catalogo_tipos_vehiculo ORDER BY orden;
