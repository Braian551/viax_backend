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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $conductor_id = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;
    $fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d');
    $fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Calcular ganancias usando comisión REAL del tracking o fallback a configuración
    // Prioridad: 
    // 1. Datos del tracking (viaje_resumen_tracking) con comisión real calculada
    // 2. Datos de solicitudes con comisión de configuración de precios
    // 3. Fallback 10% si no hay configuración
    
    $query_total = "SELECT 
        COALESCE(SUM(
            CASE 
                -- Si hay datos de tracking con ganancia calculada, usarlos
                WHEN vrt.ganancia_conductor > 0 THEN vrt.ganancia_conductor
                -- Si hay comisión en tracking, calcular
                WHEN vrt.comision_plataforma_porcentaje > 0 THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (1 - vrt.comision_plataforma_porcentaje / 100)
                -- Fallback: usar configuración de precios de la empresa
                WHEN cp.comision_plataforma IS NOT NULL THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (1 - cp.comision_plataforma / 100)
                -- Fallback final: 10% por defecto
                ELSE COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) * 0.90
            END
        ), 0) as total_ganancias,
        COALESCE(SUM(
            CASE 
                WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor
                WHEN vrt.comision_plataforma_porcentaje > 0 THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (vrt.comision_plataforma_porcentaje / 100)
                WHEN cp.comision_plataforma IS NOT NULL THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (cp.comision_plataforma / 100)
                ELSE COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) * 0.10
            END
        ), 0) as total_comision,
        COALESCE(SUM(
            COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado)
        ), 0) as total_cobrado,
        COUNT(s.id) as total_viajes,
        -- Obtener el porcentaje de comisión promedio usado
        AVG(
            CASE 
                WHEN vrt.comision_plataforma_porcentaje > 0 THEN vrt.comision_plataforma_porcentaje
                WHEN cp.comision_plataforma IS NOT NULL THEN cp.comision_plataforma
                ELSE 10
            END
        ) as comision_promedio_porcentaje
    FROM solicitudes_servicio s
    INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
    LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
    LEFT JOIN configuracion_precios cp ON s.empresa_id = cp.empresa_id 
        AND s.tipo_vehiculo = cp.tipo_vehiculo AND cp.activo = 1
    WHERE ac.conductor_id = :conductor_id
    AND s.estado IN ('completada', 'entregado')
    AND DATE(COALESCE(s.completado_en, s.solicitado_en)) BETWEEN :fecha_inicio AND :fecha_fin";
    
    $stmt_total = $db->prepare($query_total);
    $stmt_total->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_total->bindParam(':fecha_inicio', $fecha_inicio, PDO::PARAM_STR);
    $stmt_total->bindParam(':fecha_fin', $fecha_fin, PDO::PARAM_STR);
    $stmt_total->execute();
    $totales = $stmt_total->fetch(PDO::FETCH_ASSOC);

    // Calcular comisión total adeudada (de TODOS los viajes)
    $query_comision_total = "SELECT 
        COALESCE(SUM(
            CASE 
                WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor
                WHEN vrt.comision_plataforma_porcentaje > 0 THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (vrt.comision_plataforma_porcentaje / 100)
                WHEN cp.comision_plataforma IS NOT NULL THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (cp.comision_plataforma / 100)
                ELSE COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) * 0.10
            END
        ), 0) as comision_adeudada
    FROM solicitudes_servicio s
    INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
    LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
    LEFT JOIN configuracion_precios cp ON s.empresa_id = cp.empresa_id 
        AND s.tipo_vehiculo = cp.tipo_vehiculo AND cp.activo = 1
    WHERE ac.conductor_id = :conductor_id
    AND s.estado IN ('completada', 'entregado')";
    
    $stmt_comision = $db->prepare($query_comision_total);
    $stmt_comision->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_comision->execute();
    $comision_data = $stmt_comision->fetch(PDO::FETCH_ASSOC);

    // Calcular pagos realizados
    $query_pagos = "SELECT COALESCE(SUM(monto), 0) as total_pagado FROM pagos_comision WHERE conductor_id = :conductor_id";
    $stmt_pagos = $db->prepare($query_pagos);
    $stmt_pagos->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_pagos->execute();
    $pagos_data = $stmt_pagos->fetch(PDO::FETCH_ASSOC);

    $comision_total_adeudada = floatval($comision_data['comision_adeudada']);
    $total_pagado = floatval($pagos_data['total_pagado']);
    $deuda_real = max(0, $comision_total_adeudada - $total_pagado);

    // Ganancias por día con comisión real
    $query_diario = "SELECT 
        DATE(COALESCE(s.completado_en, s.solicitado_en)) as fecha,
        COALESCE(SUM(
            CASE 
                WHEN vrt.ganancia_conductor > 0 THEN vrt.ganancia_conductor
                WHEN vrt.comision_plataforma_porcentaje > 0 THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (1 - vrt.comision_plataforma_porcentaje / 100)
                WHEN cp.comision_plataforma IS NOT NULL THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (1 - cp.comision_plataforma / 100)
                ELSE COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) * 0.90
            END
        ), 0) as ganancias,
        COALESCE(SUM(
            CASE 
                WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor
                WHEN vrt.comision_plataforma_porcentaje > 0 THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (vrt.comision_plataforma_porcentaje / 100)
                WHEN cp.comision_plataforma IS NOT NULL THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (cp.comision_plataforma / 100)
                ELSE COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) * 0.10
            END
        ), 0) as comision,
        COUNT(s.id) as viajes
    FROM solicitudes_servicio s
    INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
    LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
    LEFT JOIN configuracion_precios cp ON s.empresa_id = cp.empresa_id 
        AND s.tipo_vehiculo = cp.tipo_vehiculo AND cp.activo = 1
    WHERE ac.conductor_id = :conductor_id
    AND s.estado IN ('completada', 'entregado')
    AND DATE(COALESCE(s.completado_en, s.solicitado_en)) BETWEEN :fecha_inicio AND :fecha_fin
    GROUP BY DATE(COALESCE(s.completado_en, s.solicitado_en))
    ORDER BY fecha DESC";
    
    $stmt_diario = $db->prepare($query_diario);
    $stmt_diario->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_diario->bindParam(':fecha_inicio', $fecha_inicio, PDO::PARAM_STR);
    $stmt_diario->bindParam(':fecha_fin', $fecha_fin, PDO::PARAM_STR);
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
            'comision_adeudada' => round($deuda_real, 2),
            'total_pagado' => round($total_pagado, 2),
            'comision_promedio_porcentaje' => round(floatval($totales['comision_promedio_porcentaje'] ?? 10), 2),
            'promedio_por_viaje' => $totales['total_viajes'] > 0 
                ? round(floatval($totales['total_ganancias']) / intval($totales['total_viajes']), 2)
                : 0,
            'desglose_diario' => $desglose
        ],
        'periodo' => [
            'inicio' => $fecha_inicio,
            'fin' => $fecha_fin
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
