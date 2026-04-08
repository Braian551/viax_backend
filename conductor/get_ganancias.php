<?php
/**
 * get_ganancias.php
 * Obtiene las ganancias del conductor con comisión REAL de la empresa
 * 
 * Este endpoint calcula:
 * - Ganancias totales usando la comisión real configurada por la empresa
 * - Desglose de comisiones por período
 * - Comisión total adeudada a la empresa
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

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

function parseBogotaDate(string $rawDate, DateTimeZone $tzBogota): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $rawDate, $tzBogota);
    if ($date instanceof DateTimeImmutable) {
        return $date->setTime(0, 0, 0);
    }
    return new DateTimeImmutable('now', $tzBogota);
}

function buildBogotaRangeUtc(string $startDateRaw, string $endDateRaw): array
{
    $tzBogota = new DateTimeZone('America/Bogota');
    $tzUtc = new DateTimeZone('UTC');

    $startBogota = parseBogotaDate($startDateRaw, $tzBogota);
    $endBogota = parseBogotaDate($endDateRaw, $tzBogota);

    if ($endBogota < $startBogota) {
        [$startBogota, $endBogota] = [$endBogota, $startBogota];
    }

    $endExclusiveBogota = $endBogota->modify('+1 day');

    return [
        'inicio_local' => $startBogota->format('Y-m-d'),
        'fin_local' => $endBogota->format('Y-m-d'),
        'inicio_utc' => $startBogota->setTimezone($tzUtc)->format('Y-m-d H:i:s'),
        'fin_utc_exclusive' => $endExclusiveBogota->setTimezone($tzUtc)->format('Y-m-d H:i:s'),
    ];
}

function canonicalVehicleTypeSqlExpr(string $column): string {
    $clean = "LOWER(REPLACE(REPLACE(COALESCE($column, ''), '_', ''), ' ', ''))";
    return "CASE $clean
        WHEN 'auto' THEN 'carro'
        WHEN 'automovil' THEN 'carro'
        WHEN 'car' THEN 'carro'
        WHEN 'motocarro' THEN 'mototaxi'
        WHEN 'motocarga' THEN 'mototaxi'
        ELSE $clean
    END";
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $conductor_id = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;
    $todayBogota = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $fecha_inicio = isset($_GET['fecha_inicio']) ? trim((string) $_GET['fecha_inicio']) : $todayBogota;
    $fecha_fin = isset($_GET['fecha_fin']) ? trim((string) $_GET['fecha_fin']) : $todayBogota;

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    $dateRange = buildBogotaRangeUtc($fecha_inicio, $fecha_fin);
    $completedStates = "'completada', 'completado', 'entregado', 'finalizada', 'finalizado'";
    $hasCompletedAt = hasColumn($db, 'solicitudes_servicio', 'completed_at');
    $tripDateExpr = $hasCompletedAt
        ? "COALESCE(s.completed_at, s.completado_en, s.solicitado_en, s.fecha_creacion)"
        : "COALESCE(s.completado_en, s.solicitado_en, s.fecha_creacion)";
    $tripDateBogotaExpr = "DATE(($tripDateExpr) - INTERVAL '5 hours')";

    $precioBaseExpr = "COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado, 0)";
    $trackingPctExpr = "NULLIF(vrt.comision_plataforma_porcentaje, 0)";
    $pctDesdeValorExpr = "CASE
        WHEN vrt.comision_plataforma_valor > 0 AND ($precioBaseExpr) > 0 THEN (vrt.comision_plataforma_valor * 100.0) / NULLIF(($precioBaseExpr), 0)
        ELSE NULL
    END";
    $porcentajeComisionEfectivoExpr = "CASE
        WHEN ($trackingPctExpr) IS NOT NULL THEN ($trackingPctExpr)
        WHEN ($pctDesdeValorExpr) IS NOT NULL THEN ($pctDesdeValorExpr)
        ELSE 0
    END";
    $porcentajeComisionExpr = "CASE
        WHEN vrt.solicitud_id IS NOT NULL THEN COALESCE(($porcentajeComisionEfectivoExpr), 0)
        ELSE 0
    END";
    $comisionDesdeGananciaExpr = "CASE
        WHEN ($porcentajeComisionEfectivoExpr) IS NULL OR ($porcentajeComisionEfectivoExpr) >= 100 THEN 0
        ELSE COALESCE(vrt.ganancia_conductor, 0) * (($porcentajeComisionEfectivoExpr) / NULLIF(100 - ($porcentajeComisionEfectivoExpr), 0))
    END";
    $comisionExpr = "CASE
        WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor
        WHEN vrt.solicitud_id IS NOT NULL AND COALESCE(vrt.ganancia_conductor, 0) > 0 AND ($trackingPctExpr) IS NOT NULL THEN $comisionDesdeGananciaExpr
        WHEN vrt.solicitud_id IS NOT NULL AND ($trackingPctExpr) IS NOT NULL THEN ($precioBaseExpr) * (($trackingPctExpr) / 100)
        ELSE 0
    END";
    $gananciaExpr = "CASE
        WHEN COALESCE(vrt.ganancia_conductor, 0) > 0 THEN vrt.ganancia_conductor
        ELSE ($precioBaseExpr) - ($comisionExpr)
    END";
    $cobradoExpr = "CASE
        WHEN ($precioBaseExpr) > 0 THEN ($precioBaseExpr)
        WHEN COALESCE(vrt.ganancia_conductor, 0) > 0 THEN COALESCE(vrt.ganancia_conductor, 0) + ($comisionExpr)
        ELSE 0
    END";

    // Calcular ganancias solo con comisión histórica congelada en tracking.
    // Si el histórico está en cero, la deuda debe permanecer en cero.
    
    $query_total = "SELECT 
        COALESCE(SUM($gananciaExpr), 0) as total_ganancias,
        COALESCE(SUM($comisionExpr), 0) as total_comision,
        COALESCE(SUM($cobradoExpr), 0) as total_cobrado,
        COUNT(s.id) as total_viajes,
        AVG($porcentajeComisionExpr) as comision_promedio_porcentaje
    FROM solicitudes_servicio s
    INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
    LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
    WHERE ac.conductor_id = :conductor_id
    AND LOWER(COALESCE(s.estado, '')) IN ($completedStates)
    AND $tripDateExpr >= :fecha_inicio_utc
    AND $tripDateExpr < :fecha_fin_utc";
    
    $stmt_total = $db->prepare($query_total);
    $stmt_total->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_total->bindParam(':fecha_inicio_utc', $dateRange['inicio_utc'], PDO::PARAM_STR);
    $stmt_total->bindParam(':fecha_fin_utc', $dateRange['fin_utc_exclusive'], PDO::PARAM_STR);
    $stmt_total->execute();
    $totales = $stmt_total->fetch(PDO::FETCH_ASSOC);

    // Deuda por ciclo: desde el último pago confirmado.
    // Esto evita que sobrepagos históricos oculten deuda nueva indefinidamente.
    $stmtAnchor = $db->prepare("SELECT MAX(confirmado_en) AS ultimo_pago_confirmado
        FROM pagos_comision_reportes
        WHERE conductor_id = :conductor_id
          AND estado = 'pagado_confirmado'");
    $stmtAnchor->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmtAnchor->execute();
    $anchorData = $stmtAnchor->fetch(PDO::FETCH_ASSOC) ?: [];
    $anchorTs = $anchorData['ultimo_pago_confirmado'] ?? null;

    // Calcular comisión adeudada del ciclo vigente.
    $query_comision_total = "SELECT 
        COALESCE(SUM($comisionExpr), 0) as comision_adeudada
    FROM solicitudes_servicio s
        INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
    LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
    WHERE ac.conductor_id = :conductor_id
    AND LOWER(COALESCE(s.estado, '')) IN ($completedStates)" . ($anchorTs ? "
    AND $tripDateExpr > :anchor_ts" : "");
    
    $stmt_comision = $db->prepare($query_comision_total);
    $stmt_comision->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    if ($anchorTs) {
        $stmt_comision->bindParam(':anchor_ts', $anchorTs, PDO::PARAM_STR);
    }
    $stmt_comision->execute();
    $comision_data = $stmt_comision->fetch(PDO::FETCH_ASSOC);

    // Calcular pagos realizados en el ciclo vigente.
    $query_pagos = "SELECT COALESCE(SUM(monto), 0) as total_pagado
        FROM pagos_comision
        WHERE conductor_id = :conductor_id" . ($anchorTs ? "
        AND fecha_pago > :anchor_ts" : "");
    $stmt_pagos = $db->prepare($query_pagos);
    $stmt_pagos->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    if ($anchorTs) {
        $stmt_pagos->bindParam(':anchor_ts', $anchorTs, PDO::PARAM_STR);
    }
    $stmt_pagos->execute();
    $pagos_data = $stmt_pagos->fetch(PDO::FETCH_ASSOC);

    $comision_total_adeudada = floatval($comision_data['comision_adeudada']);
    $total_pagado = floatval($pagos_data['total_pagado']);
    $deuda_real = max(0, $comision_total_adeudada - $total_pagado);

    // Ganancias por día con comisión real
    $query_diario = "SELECT 
        $tripDateBogotaExpr as fecha,
        COALESCE(SUM($gananciaExpr), 0) as ganancias,
        COALESCE(SUM($comisionExpr), 0) as comision,
        COUNT(s.id) as viajes
    FROM solicitudes_servicio s
        INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
    LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
    WHERE ac.conductor_id = :conductor_id
    AND LOWER(COALESCE(s.estado, '')) IN ($completedStates)
    AND $tripDateExpr >= :fecha_inicio_utc
    AND $tripDateExpr < :fecha_fin_utc
    GROUP BY $tripDateBogotaExpr
    ORDER BY fecha DESC";
    
    $stmt_diario = $db->prepare($query_diario);
    $stmt_diario->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_diario->bindParam(':fecha_inicio_utc', $dateRange['inicio_utc'], PDO::PARAM_STR);
    $stmt_diario->bindParam(':fecha_fin_utc', $dateRange['fin_utc_exclusive'], PDO::PARAM_STR);
    $stmt_diario->execute();
    $ganancias_diarias = $stmt_diario->fetchAll(PDO::FETCH_ASSOC);

    // Formatear desglose diario
    $desglose = array_map(function($dia) {
        return [
            'fecha' => $dia['fecha'],
            'ganancias' => round(floatval($dia['ganancias']), 2),
            'comision' => round(floatval($dia['comision']), 2),
            'viajes' => intval($dia['viajes'])
        ];
    }, $ganancias_diarias);

    echo json_encode([
        'success' => true,
        'ganancias' => [
            'total' => round(floatval($totales['total_ganancias']), 2),
            'total_cobrado' => round(floatval($totales['total_cobrado']), 2),
            'total_viajes' => intval($totales['total_viajes']),
            'comision_periodo' => round(floatval($totales['total_comision']), 2),
            'comision_empresa_periodo' => round(floatval($totales['total_comision']), 2),
            'comision_adeudada' => round($deuda_real, 2),
            'comision_empresa_adeudada' => round($deuda_real, 2),
            'total_pagado' => round($total_pagado, 2),
            'comision_promedio_porcentaje' => round(floatval($totales['comision_promedio_porcentaje'] ?? 10), 2),
            'comision_empresa_promedio_porcentaje' => round(floatval($totales['comision_promedio_porcentaje'] ?? 10), 2),
            'promedio_por_viaje' => $totales['total_viajes'] > 0 
                ? round(floatval($totales['total_ganancias']) / intval($totales['total_viajes']), 2)
                : 0,
            'desglose_diario' => $desglose
        ],
        'periodo' => [
            'inicio' => $dateRange['inicio_local'],
            'fin' => $dateRange['fin_local']
        ],
        'message' => 'Ganancias obtenidas exitosamente'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
