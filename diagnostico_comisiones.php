<?php
/**
 * Diagnóstico: Verificar comisiones y saldos
 * 
 * Muestra el estado actual de:
 * - Viajes finalizados con comision_admin_valor
 * - Saldo pendiente de empresas
 * - Pagos registrados
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $result = [];
    
    // 1. Empresas y sus saldos
    $empresasQuery = $db->query("
        SELECT 
            id, 
            nombre, 
            comision_admin_porcentaje,
            saldo_pendiente,
            total_viajes_completados
        FROM empresas_transporte 
        WHERE estado = 'activo'
        ORDER BY nombre
    ");
    $result['empresas'] = $empresasQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Viajes finalizados con comisión admin
    $viajesQuery = $db->query("
        SELECT 
            vrt.empresa_id,
            et.nombre as empresa_nombre,
            COUNT(*) as total_viajes,
            SUM(vrt.precio_total) as total_facturado,
            SUM(vrt.comision_plataforma_valor) as total_comision_empresa,
            SUM(vrt.comision_admin_valor) as total_comision_admin,
            SUM(vrt.ganancia_empresa) as total_ganancia_empresa
        FROM viaje_resumen_tracking vrt
        JOIN empresas_transporte et ON et.id = vrt.empresa_id
        WHERE vrt.estado = 'finalizado'
        GROUP BY vrt.empresa_id, et.nombre
        ORDER BY total_comision_admin DESC
    ");
    $result['viajes_por_empresa'] = $viajesQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Pagos registrados
    $pagosQuery = $db->query("
        SELECT 
            pe.empresa_id,
            et.nombre as empresa_nombre,
            pe.tipo,
            COUNT(*) as cantidad,
            SUM(pe.monto) as total_monto
        FROM pagos_empresas pe
        JOIN empresas_transporte et ON et.id = pe.empresa_id
        GROUP BY pe.empresa_id, et.nombre, pe.tipo
        ORDER BY et.nombre, pe.tipo
    ");
    $result['pagos_por_empresa'] = $pagosQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Últimos 10 viajes finalizados (detalle)
    $ultimosViajesQuery = $db->query("
        SELECT 
            vrt.id,
            vrt.solicitud_id,
            vrt.empresa_id,
            et.nombre as empresa_nombre,
            vrt.precio_total,
            vrt.comision_plataforma_porcentaje,
            vrt.comision_plataforma_valor,
            vrt.comision_admin_porcentaje,
            vrt.comision_admin_valor,
            vrt.ganancia_empresa,
            vrt.estado,
            vrt.creado_en
        FROM viaje_resumen_tracking vrt
        LEFT JOIN empresas_transporte et ON et.id = vrt.empresa_id
        WHERE vrt.estado = 'finalizado'
        ORDER BY vrt.creado_en DESC
        LIMIT 10
    ");
    $result['ultimos_viajes'] = $ultimosViajesQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Resumen general
    $resumenQuery = $db->query("
        SELECT 
            COUNT(*) as total_viajes_finalizados,
            COALESCE(SUM(comision_admin_valor), 0) as total_comision_admin_generada,
            COALESCE(SUM(ganancia_empresa), 0) as total_ganancia_empresas
        FROM viaje_resumen_tracking
        WHERE estado = 'finalizado'
    ");
    $result['resumen'] = $resumenQuery->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
