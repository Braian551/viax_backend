-- Unifica constraints legacy con el modelo nuevo de reportes_usuarios.

ALTER TABLE reportes_usuarios
    DROP CONSTRAINT IF EXISTS reportes_usuarios_estado_check;

ALTER TABLE reportes_usuarios
    ADD CONSTRAINT reportes_usuarios_estado_check
    CHECK (estado IN ('pendiente', 'en_revision', 'resuelto', 'descartado', 'rechazado'));

ALTER TABLE reportes_usuarios
    DROP CONSTRAINT IF EXISTS reportes_usuarios_tipo_reporte_check;

ALTER TABLE reportes_usuarios
    ADD CONSTRAINT reportes_usuarios_tipo_reporte_check
    CHECK (
        tipo_reporte IN (
            'conducta_inapropiada',
            'fraude',
            'seguridad',
            'otro',
            'comportamiento_inapropiado',
            'acoso_o_amenaza',
            'fraude_o_estafa',
            'incumplimiento_servicio',
            'contenido_inapropiado_chat'
        )
    );
