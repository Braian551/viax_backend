<?php
/**
 * API: Ganancias de la Plataforma (Admin)
 * Endpoint: admin/platform_earnings.php
 * 
 * Retorna las ganancias totales de la plataforma VIAX:
 * - Total que deben todas las empresas (saldo_pendiente)
 * - Histórico de pagos recibidos de empresas
 * - Desglose por empresa
 * - Ganancias del período (comisiones generadas)
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 'mes';
    
    // Determinar rango de fechas según período
    $fecha_fin = date('Y-m-d');
    switch ($periodo) {
        case 'hoy':
            $fecha_inicio = date('Y-m-d');
            break;
        case 'semana':
            $fecha_inicio = date('Y-m-d', strtotime('-7 days'));
            break;
        case 'anio':
            $fecha_inicio = date('Y-01-01');
            break;
        case 'todo':
            $fecha_inicio = '2020-01-01';
            break;
        default: // mes
            $fecha_inicio = date('Y-m-01');
    }

    // 1. Total que deben TODAS las empresas (cuenta por cobrar)
    $stmt = $db->query("SELECT COALESCE(SUM(saldo_pendiente), 0) as total_por_cobrar FROM empresas_transporte WHERE estado = 'activo'");
    $total_por_cobrar = floatval($stmt->fetchColumn());

    // 2. Total de pagos recibidos de empresas (histórico)
    $stmt = $db->query("SELECT COALESCE(SUM(monto), 0) as total_recibido FROM pagos_empresas WHERE tipo = 'pago'");
    $total_recibido_historico = floatval($stmt->fetchColumn());

    // 3. Comisiones generadas en el período (ganancias del período)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(comision_admin_valor), 0) as ganancias_periodo
        FROM viaje_resumen_tracking vrt
        WHERE DATE(vrt.fin_viaje_real) >= :fecha_inicio
        AND DATE(vrt.fin_viaje_real) <= :fecha_fin
        AND comision_admin_valor > 0
    ");
    $stmt->execute([':fecha_inicio' => $fecha_inicio, ':fecha_fin' => $fecha_fin]);
    $ganancias_periodo = floatval($stmt->fetchColumn());

    // 4. Pagos recibidos en el período
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(monto), 0) as pagos_periodo
        FROM pagos_empresas
        WHERE tipo = 'pago'
        AND DATE(creado_en) >= :fecha_inicio
        AND DATE(creado_en) <= :fecha_fin
    ");
    $stmt->execute([':fecha_inicio' => $fecha_inicio, ':fecha_fin' => $fecha_fin]);
    $pagos_recibidos_periodo = floatval($stmt->fetchColumn());

    // 5. Desglose por empresa (top 10 deudoras)
    $stmt = $db->query("
        SELECT 
            et.id,
            et.nombre,
            et.logo_url,
            et.comision_admin_porcentaje,
            et.saldo_pendiente,
            et.total_viajes_completados,
            (SELECT COALESCE(SUM(monto), 0) FROM pagos_empresas pe WHERE pe.empresa_id = et.id AND pe.tipo = 'pago') as total_pagado
        FROM empresas_transporte et
        WHERE et.estado = 'activo'
        ORDER BY et.saldo_pendiente DESC
        LIMIT 10
    ");
    $empresas_deudoras = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Últimos movimientos (pagos recibidos de empresas)
    $stmt = $db->query("
        SELECT 
            pe.id,
            pe.empresa_id,
            et.nombre as empresa_nombre,
            pe.monto,
            pe.tipo,
            pe.descripcion,
            pe.creado_en
        FROM pagos_empresas pe
        JOIN empresas_transporte et ON pe.empresa_id = et.id
        ORDER BY pe.creado_en DESC
        LIMIT 20
    ");
    $ultimos_movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. Estadísticas de viajes del período
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_viajes,
            COALESCE(SUM(precio_final_aplicado), 0) as total_facturado,
            COALESCE(SUM(comision_plataforma_valor), 0) as total_comisiones_empresa,
            COALESCE(SUM(comision_admin_valor), 0) as total_comisiones_admin
        FROM viaje_resumen_tracking vrt
        WHERE DATE(vrt.fin_viaje_real) >= :fecha_inicio
        AND DATE(vrt.fin_viaje_real) <= :fecha_fin
    ");
    $stmt->execute([':fecha_inicio' => $fecha_inicio, ':fecha_fin' => $fecha_fin]);
    $stats_viajes = $stmt->fetch(PDO::FETCH_ASSOC);

    // Respuesta
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'resumen' => [
                'total_por_cobrar' => $total_por_cobrar,
                'total_recibido_historico' => $total_recibido_historico,
                'ganancias_periodo' => $ganancias_periodo,
                'pagos_recibidos_periodo' => $pagos_recibidos_periodo,
                'periodo' => $periodo,
                'fecha_inicio' => $fecha_inicio,
                'fecha_fin' => $fecha_fin
            ],
            'estadisticas_viajes' => [
                'total_viajes' => intval($stats_viajes['total_viajes']),
                'total_facturado' => floatval($stats_viajes['total_facturado']),
                'total_comisiones_empresa' => floatval($stats_viajes['total_comisiones_empresa']),
                'total_comisiones_admin' => floatval($stats_viajes['total_comisiones_admin'])
            ],
            'empresas_deudoras' => array_map(function($e) {
                return [
                    'id' => intval($e['id']),
                    'nombre' => $e['nombre'],
                    'logo_url' => $e['logo_url'],
                    'comision_porcentaje' => floatval($e['comision_admin_porcentaje']),
                    'saldo_pendiente' => floatval($e['saldo_pendiente']),
                    'total_viajes' => intval($e['total_viajes_completados']),
                    'total_pagado' => floatval($e['total_pagado'])
                ];
            }, $empresas_deudoras),
            'ultimos_movimientos' => array_map(function($m) {
                return [
                    'id' => intval($m['id']),
                    'empresa_id' => intval($m['empresa_id']),
                    'empresa_nombre' => $m['empresa_nombre'],
                    'monto' => floatval($m['monto']),
                    'tipo' => $m['tipo'],
                    'descripcion' => $m['descripcion'],
                    'fecha' => $m['creado_en']
                ];
            }, $ultimos_movimientos)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>
