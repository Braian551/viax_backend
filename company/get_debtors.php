<?php
/**
 * API: Obtener Deudores de Empresa
 * Endpoint: company/get_debtors.php
 * 
 * Devuelve una lista de conductores de la empresa con su estado financiero
 * (comisión total calculada, pagada y deuda actual).
 */

require_once '../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    if (empty($_GET['empresa_id'])) {
        throw new Exception('ID de empresa requerido');
    }
    
    $empresaId = $_GET['empresa_id'];
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Consulta compleja para obtener conductores y calcular su deuda
    // 1. Obtener conductores de la empresa
    // 2. Sumar comision_calculada de viajes finalizados (viaje_resumen_tracking via asignaciones_conductor)
    // 3. Sumar pagos realizados (pagos_comision)
    
    $query = "
        SELECT 
            u.id, 
            u.nombre, 
            u.apellido, 
            u.email, 
            u.telefono,
            u.foto_perfil,
            COALESCE(comisiones.total_comision, 0) as total_comision,
            COALESCE(pagos.total_pagado, 0) as total_pagado
        FROM usuarios u
        -- Subconsulta para comisiones
        LEFT JOIN (
            SELECT 
                ac.conductor_id,
                SUM(
                    CASE 
                        WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor
                        WHEN vrt.comision_plataforma_porcentaje > 0 THEN 
                            COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                            * (vrt.comision_plataforma_porcentaje / 100)
                        ELSE COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) * 0.10
                    END
                ) as total_comision
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
            WHERE s.estado IN ('completada', 'entregado')
            GROUP BY ac.conductor_id
        ) comisiones ON comisiones.conductor_id = u.id
        -- Subconsulta para pagos
        LEFT JOIN (
            SELECT conductor_id, SUM(monto) as total_pagado
            FROM pagos_comision
            GROUP BY conductor_id
        ) pagos ON pagos.conductor_id = u.id
        WHERE u.empresa_id = :empresa_id
        AND u.tipo_usuario = 'conductor'
        ORDER BY (COALESCE(comisiones.total_comision, 0) - COALESCE(pagos.total_pagado, 0)) DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':empresa_id', $empresaId);
    $stmt->execute();
    
    $conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular deuda real en PHP
    $deudores = [];
    $totalDeulaGlobal = 0;
    
    foreach ($conductores as $c) {
        $totalComision = floatval($c['total_comision']);
        $totalPagado = floatval($c['total_pagado']);
        $deuda = $totalComision - $totalPagado;
        
        // Formatear para respuesta
        $c['deuda_actual'] = $deuda > 0 ? $deuda : 0;
        $c['saldo_a_favor'] = $deuda < 0 ? abs($deuda) : 0; // Si pagó de más (raro pero posible)
        
        if ($deuda > 0) {
            $totalDeulaGlobal += $deuda;
        }
        
        $deudores[] = $c;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $deudores,
        'resumen' => [
            'total_conductores' => count($deudores),
            'deuda_total_empresa' => $totalDeulaGlobal
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
