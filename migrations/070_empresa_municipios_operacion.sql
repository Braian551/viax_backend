-- Migracion 070: Municipios de operacion por empresa
-- Normaliza la cobertura operativa para que una empresa pueda operar en
-- multiples municipios sin depender de un arreglo de texto.

CREATE TABLE IF NOT EXISTS empresa_municipios_operacion (
    id BIGSERIAL PRIMARY KEY,
    empresa_id BIGINT NOT NULL REFERENCES empresas_transporte(id) ON DELETE CASCADE,
    departamento VARCHAR(100) NOT NULL,
    municipio VARCHAR(100) NOT NULL,
    es_principal BOOLEAN DEFAULT FALSE,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ux_empresa_municipio_operacion UNIQUE (empresa_id, departamento, municipio)
);

CREATE INDEX IF NOT EXISTS idx_empresa_municipios_operacion_empresa
    ON empresa_municipios_operacion(empresa_id);

CREATE INDEX IF NOT EXISTS idx_empresa_municipios_operacion_busqueda
    ON empresa_municipios_operacion(LOWER(departamento), LOWER(municipio));

INSERT INTO empresa_municipios_operacion (empresa_id, departamento, municipio, es_principal)
SELECT
    ec.empresa_id,
    COALESCE(NULLIF(TRIM(ec.departamento), ''), 'Sin departamento') AS departamento,
    TRIM(ec.municipio) AS municipio,
    TRUE AS es_principal
FROM empresas_contacto ec
WHERE NULLIF(TRIM(ec.municipio), '') IS NOT NULL
ON CONFLICT (empresa_id, departamento, municipio) DO UPDATE SET
    es_principal = empresa_municipios_operacion.es_principal OR EXCLUDED.es_principal,
    actualizado_en = NOW();

INSERT INTO empresa_municipios_operacion (empresa_id, departamento, municipio, es_principal)
SELECT
    e.id,
    COALESCE(NULLIF(TRIM(e.departamento), ''), 'Sin departamento') AS departamento,
    TRIM(e.municipio) AS municipio,
    TRUE AS es_principal
FROM empresas_transporte e
WHERE NULLIF(TRIM(e.municipio), '') IS NOT NULL
ON CONFLICT (empresa_id, departamento, municipio) DO UPDATE SET
    es_principal = empresa_municipios_operacion.es_principal OR EXCLUDED.es_principal,
    actualizado_en = NOW();

INSERT INTO empresa_municipios_operacion (empresa_id, departamento, municipio, es_principal)
SELECT
    ecf.empresa_id,
    COALESCE(NULLIF(TRIM(ec.departamento), ''), 'Sin departamento') AS departamento,
    TRIM(zona) AS municipio,
    FALSE AS es_principal
FROM empresas_configuracion ecf
LEFT JOIN empresas_contacto ec ON ec.empresa_id = ecf.empresa_id
CROSS JOIN LATERAL unnest(COALESCE(ecf.zona_operacion, ARRAY[]::TEXT[])) AS zona
WHERE NULLIF(TRIM(zona), '') IS NOT NULL
  AND LOWER(TRIM(zona)) <> LOWER(COALESCE(TRIM(ec.departamento), ''))
ON CONFLICT (empresa_id, departamento, municipio) DO NOTHING;

COMMENT ON TABLE empresa_municipios_operacion IS 'Municipios donde opera cada empresa de transporte';
