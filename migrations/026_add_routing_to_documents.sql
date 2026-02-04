-- Migración 026: Agregar enrutamiento de documentos (Admin vs Empresa)
-- Fecha: 2025-12-31
-- Descripción: Agrega columnas para saber quién debe verificar el documento

ALTER TABLE documentos_conductor_historial
ADD COLUMN asignado_empresa_id BIGINT NULL,
ADD COLUMN verificado_por_admin BOOLEAN DEFAULT FALSE,
ADD CONSTRAINT fk_doc_historial_empresa FOREIGN KEY (asignado_empresa_id) REFERENCES empresas_transporte (id) ON DELETE SET NULL;

COMMENT ON COLUMN documentos_conductor_historial.asignado_empresa_id IS 'ID de la empresa que debe verificar este documento';
COMMENT ON COLUMN documentos_conductor_historial.verificado_por_admin IS 'True si debe ser verificado por el admin de la plataforma';

-- Agregar índice para búsquedas rápidas por empresa
CREATE INDEX idx_doc_empresa ON documentos_conductor_historial (asignado_empresa_id);
