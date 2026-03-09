<?php
/**
 * API: Empresas deudoras con la plataforma (Admin)
 * Endpoint: GET admin/empresas_deudoras.php
 * 
 * Lista todas las empresas con su deuda, pagos y comisiones.
 * Similar a get_debtors.php pero para el nivel empresa→admin.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    // Obtener todas las empresas activas con sus deudas
    $stmt = $db->query("
        SELECT 
            et.id,
            et.nombre,
            et.email,
            et.representante_email,
            et.logo_url,
            et.comision_admin_porcentaje,
            et.saldo_pendiente,
            et.total_viajes_completados,
            COALESCE(
                (SELECT SUM(monto) FROM pagos_empresas pe WHERE pe.empresa_id = et.id AND pe.tipo = 'cargo'), 0
            ) AS total_cargos,
            COALESCE(
                (SELECT SUM(monto) FROM pagos_empresas pe WHERE pe.empresa_id = et.id AND pe.tipo = 'pago'), 0
            ) AS total_pagado,
            (SELECT COUNT(*) FROM pagos_empresa_reportes per 
             WHERE per.empresa_id = et.id AND per.estado = 'pendiente_revision'
            ) AS reportes_pendientes
        FROM empresas_transporte et
        WHERE et.estado = 'activo'
        ORDER BY et.saldo_pendiente DESC
    ");

    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcular resumen general
    $deudaTotal = 0;
    $totalPagadoGlobal = 0;
    $totalReportesPendientes = 0;
    $empresasConDeuda = 0;

    foreach ($empresas as &$e) {
        $e['id'] = intval($e['id']);
        $e['saldo_pendiente'] = floatval($e['saldo_pendiente']);
        $e['total_cargos'] = floatval($e['total_cargos']);
        $e['total_pagado'] = floatval($e['total_pagado']);
        $e['comision_admin_porcentaje'] = floatval($e['comision_admin_porcentaje']);
        $e['total_viajes_completados'] = intval($e['total_viajes_completados']);
        $e['reportes_pendientes'] = intval($e['reportes_pendientes']);

        $deudaTotal += $e['saldo_pendiente'];
        $totalPagadoGlobal += $e['total_pagado'];
        $totalReportesPendientes += $e['reportes_pendientes'];
        if ($e['saldo_pendiente'] > 0) {
            $empresasConDeuda++;
        }
    }
    unset($e);

    echo json_encode([
        'success' => true,
        'data' => $empresas,
        'resumen' => [
            'total_empresas' => count($empresas),
            'empresas_con_deuda' => $empresasConDeuda,
            'deuda_total' => $deudaTotal,
            'total_pagado_global' => $totalPagadoGlobal,
            'reportes_pendientes' => $totalReportesPendientes,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
