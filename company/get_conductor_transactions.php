<?php
/**
 * API: Obtener Historial Financiero de Conductor
 * Endpoint: company/get_conductor_transactions.php
 * 
 * Devuelve un historial unificado de transacciones (Cobros de comisión y Pagos realizados)
 * para generar un estado de cuenta detallado.
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
    if (empty($_GET['conductor_id'])) {
        throw new Exception('ID de conductor requerido');
    }
    
    $conductorId = $_GET['conductor_id'];
    
    $database = new Database();
    $db = $database->getConnection();
    
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
                    ELSE COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) * 0.10
                END as monto,
                CONCAT('Viaje #', s.id) as descripcion,
                '' as detalle_extra
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
            WHERE ac.conductor_id = :conductor_id_1
            AND s.estado IN ('completada', 'entregado')
            
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
        'data' => $historial
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
