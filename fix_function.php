<?php
/**
 * Fix stored function aprobar_vinculacion_conductor
 * Change actualizado_en to fecha_actualizacion
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Redefining function aprobar_vinculacion_conductor...\n";
    
    $sql = "CREATE OR REPLACE FUNCTION aprobar_vinculacion_conductor(
        p_solicitud_id BIGINT,
        p_aprobado_por BIGINT
    ) RETURNS JSON AS $$
    DECLARE
        v_conductor_id BIGINT;
        v_empresa_id BIGINT;
        v_resultado JSON;
    BEGIN
        -- Obtener datos de la solicitud
        SELECT conductor_id, empresa_id INTO v_conductor_id, v_empresa_id
        FROM solicitudes_vinculacion_conductor
        WHERE id = p_solicitud_id AND estado = 'pendiente';
        
        IF v_conductor_id IS NULL THEN
            RETURN json_build_object('success', false, 'message', 'Solicitud no encontrada o ya procesada');
        END IF;
        
        -- Actualizar solicitud
        UPDATE solicitudes_vinculacion_conductor
        SET estado = 'aprobada', procesado_por = p_aprobado_por, procesado_en = CURRENT_TIMESTAMP
        WHERE id = p_solicitud_id;
        
        -- Vincular conductor a empresa y reactivar
        UPDATE usuarios
        SET empresa_id = v_empresa_id, 
            estado_vinculacion = 'vinculado',
            es_activo = 1, 
            fecha_actualizacion = CURRENT_TIMESTAMP -- Fixed column name
        WHERE id = v_conductor_id;
        
        -- Actualizar contador de conductores en empresa
        UPDATE empresas_transporte
        SET total_conductores = total_conductores + 1, actualizado_en = CURRENT_TIMESTAMP
        WHERE id = v_empresa_id;
        
        -- Rechazar otras solicitudes pendientes del mismo conductor
        UPDATE solicitudes_vinculacion_conductor
        SET estado = 'rechazada', 
            respuesta_empresa = 'Conductor vinculado a otra empresa',
            procesado_en = CURRENT_TIMESTAMP
        WHERE conductor_id = v_conductor_id AND estado = 'pendiente' AND id != p_solicitud_id;
        
        RETURN json_build_object(
            'success', true, 
            'message', 'Conductor vinculado exitosamente',
            'conductor_id', v_conductor_id,
            'empresa_id', v_empresa_id
        );
    END;
    $$ LANGUAGE plpgsql;";
    
    $db->exec($sql);
    echo "✅ Function redefined successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
