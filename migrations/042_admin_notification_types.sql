-- =====================================================
-- MIGRACIÓN 042: Tipos de notificación para Admin
-- Fecha: 2026-03-03
-- Descripción: Agrega tipos orientados al rol administrador
-- =====================================================

INSERT INTO tipos_notificacion (codigo, nombre, descripcion, icono, color)
VALUES
    (
        'admin_company_registration_pending',
        'Empresa pendiente de revisión',
        'Nueva empresa registrada pendiente de revisión administrativa',
        'business',
        '#00BCD4'
    ),
    (
        'admin_company_documents_submitted',
        'Documentos empresariales enviados',
        'Una empresa actualizó o envió documentación para validación',
        'description',
        '#FF9800'
    ),
    (
        'admin_company_payment_info_updated',
        'Datos de pago actualizados',
        'Una empresa actualizó datos bancarios o referencia de transferencia',
        'payment',
        '#4CAF50'
    )
ON CONFLICT (codigo) DO NOTHING;
