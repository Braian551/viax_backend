<?php
/**
 * API: Obtener Historial Financiero de Conductor
 * Endpoint: company/get_conductor_transactions.php
 * 
 * Devuelve un historial unificado de transacciones (Cobros de comisión y Pagos realizados)
 * para generar un estado de cuenta detallado.
 */

require_once '../config/database.php';

function hasColumn(PDO $db, string $table, string $column): bool
{
        $sql = "SELECT 1
                        FROM information_schema.columns
                        WHERE table_schema = 'public'
                            AND table_name = :table
                            AND column_name = :column
                        LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([
                ':table' => $table,
                ':column' => $column,
        ]);
        return (bool) $stmt->fetchColumn();
}

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    if (empty($_GET['conductor_id'])) {
        throw new Exception('ID de conductor requerido');
    }
    
    $conductorId = intval($_GET['conductor_id']);
    if ($conductorId <= 0) {
        throw new Exception('ID de conductor inválido');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    $completedStates = "'completada', 'completado', 'entregado', 'finalizada', 'finalizado'";
    $hasCompletedAt = hasColumn($db, 'solicitudes_servicio', 'completed_at');
    $tripDateExpr = $hasCompletedAt
        ? "COALESCE(s.completed_at, s.completado_en, s.solicitado_en, s.fecha_creacion)"
        : "COALESCE(s.completado_en, s.solicitado_en, s.fecha_creacion)";

    $stmtConductor = $db->prepare("SELECT id, empresa_id FROM usuarios WHERE id = :id AND tipo_usuario = 'conductor' LIMIT 1");
    $stmtConductor->execute([':id' => $conductorId]);
    $conductor = $stmtConductor->fetch(PDO::FETCH_ASSOC);

    if (!$conductor) {
        throw new Exception('Conductor no encontrado');
    }

    // Deuda por ciclo: desde el último pago confirmado del conductor.
    $stmtAnchor = $db->prepare("SELECT MAX(confirmado_en) AS ultimo_pago_confirmado
        FROM pagos_comision_reportes
        WHERE conductor_id = :conductor_id
          AND estado = 'pagado_confirmado'");
    $stmtAnchor->execute([':conductor_id' => $conductorId]);
    $anchorData = $stmtAnchor->fetch(PDO::FETCH_ASSOC) ?: [];
    $anchorTs = $anchorData['ultimo_pago_confirmado'] ?? null;

    $queryResumen = "
        SELECT
            COALESCE(comisiones.total_comision, 0) AS total_comision,
            COALESCE(pagos.total_pagado, 0) AS total_pagado
        FROM usuarios u
        LEFT JOIN (
            SELECT
                ac.conductor_id,
                SUM(
                    CASE
                        WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor
                        WHEN vrt.comision_plataforma_porcentaje > 0 THEN
                            COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado)
                            * (vrt.comision_plataforma_porcentaje / 100)
                        ELSE 0
                    END
                ) AS total_comision
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
                        WHERE LOWER(COALESCE(s.estado, '')) IN ($completedStates)
                            " . ($anchorTs ? "AND $tripDateExpr > :anchor_ts_comisiones" : "") . "
            GROUP BY ac.conductor_id
        ) comisiones ON comisiones.conductor_id = u.id
        LEFT JOIN (
            SELECT conductor_id, SUM(monto) AS total_pagado
            FROM pagos_comision
            " . ($anchorTs ? "WHERE fecha_pago > :anchor_ts_pagos" : "") . "
            GROUP BY conductor_id
        ) pagos ON pagos.conductor_id = u.id
        WHERE u.id = :conductor_id
        LIMIT 1
    ";

    $stmtResumen = $db->prepare($queryResumen);
    $paramsResumen = [':conductor_id' => $conductorId];
    if ($anchorTs) {
        $paramsResumen[':anchor_ts_comisiones'] = $anchorTs;
        $paramsResumen[':anchor_ts_pagos'] = $anchorTs;
    }
    $stmtResumen->execute($paramsResumen);
    $resumen = $stmtResumen->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalComision = floatval($resumen['total_comision'] ?? 0);
    $totalPagado = floatval($resumen['total_pagado'] ?? 0);
    $deudaActual = max(0, $totalComision - $totalPagado);
    
    // Consulta UNION para obtener eventos cronológicos
    // Tipo: 'cargo' (comisión de viaje) o 'abono' (pago realizado)
    
    $query = "
        SELECT * FROM (
            -- 1. Cargos por comisión de viajes
            SELECT 
                'cargo' as tipo,
                s.id as referencia_id,
                COALESCE(s.completado_en, s.solicitado_en) as fecha,
                CASE 
                    WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor
                    WHEN vrt.comision_plataforma_porcentaje > 0 THEN 
                        COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                        * (vrt.comision_plataforma_porcentaje / 100)
                    ELSE 0
                END as monto,
                CONCAT('Viaje #', s.id) as descripcion,
                '' as detalle_extra
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
            WHERE ac.conductor_id = :conductor_id_1
            AND LOWER(COALESCE(s.estado, '')) IN ($completedStates)
            
            UNION ALL
            
            -- 2. Abonos por pagos realizados
            SELECT 
                'abono' as tipo,
                p.id as referencia_id,
                p.fecha_pago as fecha,
                p.monto as monto,
                'Pago realizado' as descripcion,
                p.notas as detalle_extra
            FROM pagos_comision p
            WHERE p.conductor_id = :conductor_id_2
        ) as transacciones
        ORDER BY fecha DESC
        LIMIT 100
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':conductor_id_1', $conductorId);
    $stmt->bindParam(':conductor_id_2', $conductorId);
    $stmt->execute();
    
    $transacciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear numeros
    $historial = array_map(function($t) {
        return [
            'tipo' => $t['tipo'], // cargo | abono
            'referencia_id' => $t['referencia_id'],
            'fecha' => $t['fecha'],
            'monto' => round(floatval($t['monto']), 2),
            'descripcion' => $t['descripcion'],
            'detalle' => $t['detalle_extra']
        ];
    }, $transacciones);
    
    echo json_encode([
        'success' => true,
        'data' => $historial,
        'resumen' => [
            'total_comision' => round($totalComision, 2),
            'total_pagado' => round($totalPagado, 2),
            'deuda_actual' => round($deudaActual, 2),
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
