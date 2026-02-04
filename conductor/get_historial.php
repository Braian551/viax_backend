<?php
/**
 * get_historial.php
 * Obtiene el historial de viajes del conductor con DESGLOSE COMPLETO de precios
 * 
 * Este endpoint incluye:
 * - Todos los recargos aplicados (festivo, hora pico, nocturno, espera)
 * - Comisión REAL de la empresa (no hardcodeada)
 * - Ganancia real del conductor
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
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $offset = ($page - 1) * $limit;

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Contar total de viajes
    $query_count = "SELECT COUNT(*) as total
                    FROM solicitudes_servicio s
                    INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
                    WHERE ac.conductor_id = :conductor_id
                    AND s.estado IN ('completada', 'entregado')";
    
    $stmt_count = $db->prepare($query_count);
    $stmt_count->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_count->execute();
    $total = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];

    // Obtener historial con DESGLOSE COMPLETO del tracking
    $query = "SELECT 
                s.id,
                s.tipo_servicio,
                s.tipo_vehiculo,
                s.estado,
                s.empresa_id,
                -- Distancia REAL
                COALESCE(
                    NULLIF(vrt.distancia_real_km, 0),
                    NULLIF(s.distancia_recorrida, 0)
                ) as distancia_km,
                -- Tiempo en SEGUNDOS
                COALESCE(
                    NULLIF(vrt.tiempo_real_minutos, 0) * 60,
                    CASE 
                        WHEN s.completado_en IS NOT NULL AND s.aceptado_en IS NOT NULL 
                        THEN EXTRACT(EPOCH FROM (s.completado_en - s.aceptado_en))
                        ELSE NULL
                    END
                ) as duracion_segundos,
                s.tiempo_estimado as duracion_estimada,
                s.distancia_estimada,
                s.solicitado_en as fecha_solicitud,
                s.completado_en as fecha_completado,
                s.aceptado_en as fecha_aceptado,
                s.direccion_recogida as origen,
                s.direccion_destino as destino,
                s.precio_estimado,
                s.precio_final,
                s.metodo_pago,
                s.pago_confirmado,
                s.desglose_precio,
                u.nombre as cliente_nombre,
                u.apellido as cliente_apellido,
                c.calificacion,
                c.comentarios,
                -- DATOS DEL TRACKING CON DESGLOSE COMPLETO
                vrt.distancia_real_km as tracking_distancia,
                vrt.tiempo_real_minutos as tracking_tiempo,
                vrt.precio_final_aplicado as tracking_precio,
                -- Desglose de precio del tracking
                vrt.tarifa_base,
                vrt.precio_distancia,
                vrt.precio_tiempo,
                vrt.recargo_nocturno,
                vrt.recargo_hora_pico,
                vrt.recargo_festivo,
                vrt.recargo_espera,
                vrt.tiempo_espera_min,
                -- Comisión REAL de la empresa
                vrt.comision_plataforma_porcentaje,
                vrt.comision_plataforma_valor,
                vrt.ganancia_conductor,
                -- Fallback: configuración de precios para comisión
                cp.comision_plataforma as config_comision
              FROM solicitudes_servicio s
              INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
              INNER JOIN usuarios u ON s.cliente_id = u.id
              LEFT JOIN calificaciones c ON s.id = c.solicitud_id AND c.usuario_calificado_id = :conductor_id2
              LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
              LEFT JOIN configuracion_precios cp ON s.empresa_id = cp.empresa_id 
                  AND s.tipo_vehiculo = cp.tipo_vehiculo AND cp.activo = 1
              WHERE ac.conductor_id = :conductor_id
              AND s.estado IN ('completada', 'entregado')
              ORDER BY s.id DESC
              LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt->bindParam(':conductor_id2', $conductor_id, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $viajes = array_map(function($viaje) {
        // Precio REAL: prioridad -> tracking_precio > precio_final > precio_estimado
        $precioReal = 0;
        if (isset($viaje['tracking_precio']) && $viaje['tracking_precio'] > 0) {
            $precioReal = (float)$viaje['tracking_precio'];
        } elseif (isset($viaje['precio_final']) && $viaje['precio_final'] > 0) {
            $precioReal = (float)$viaje['precio_final'];
        } else {
            $precioReal = (float)($viaje['precio_estimado'] ?? 0);
        }
        
        // Comisión REAL de la empresa
        $comisionPorcentaje = 10.0; // Fallback
        $comisionValor = 0;
        $gananciaViaje = 0;
        
        // Prioridad: tracking > config > fallback 10%
        if (isset($viaje['comision_plataforma_valor']) && $viaje['comision_plataforma_valor'] > 0) {
            $comisionValor = (float)$viaje['comision_plataforma_valor'];
            $comisionPorcentaje = (float)($viaje['comision_plataforma_porcentaje'] ?? 10);
            $gananciaViaje = (float)($viaje['ganancia_conductor'] ?? ($precioReal - $comisionValor));
        } elseif (isset($viaje['config_comision']) && $viaje['config_comision'] > 0) {
            $comisionPorcentaje = (float)$viaje['config_comision'];
            $comisionValor = $precioReal * ($comisionPorcentaje / 100);
            $gananciaViaje = $precioReal - $comisionValor;
        } else {
            $comisionValor = $precioReal * 0.10;
            $gananciaViaje = $precioReal * 0.90;
        }
        
        // Construir desglose de precio
        $desglose = null;
        
        // Primero intentar obtener del campo desglose_precio (JSON)
        if (!empty($viaje['desglose_precio'])) {
            $desgloseJson = is_string($viaje['desglose_precio']) 
                ? json_decode($viaje['desglose_precio'], true) 
                : $viaje['desglose_precio'];
            if ($desgloseJson && is_array($desgloseJson)) {
                $desglose = $desgloseJson;
            }
        }
        
        // Si no hay desglose en JSON, construir desde columnas del tracking
        if (!$desglose && isset($viaje['tarifa_base'])) {
            $desglose = [
                'tarifa_base' => (float)($viaje['tarifa_base'] ?? 0),
                'precio_distancia' => (float)($viaje['precio_distancia'] ?? 0),
                'precio_tiempo' => (float)($viaje['precio_tiempo'] ?? 0),
                'recargo_nocturno' => (float)($viaje['recargo_nocturno'] ?? 0),
                'recargo_hora_pico' => (float)($viaje['recargo_hora_pico'] ?? 0),
                'recargo_festivo' => (float)($viaje['recargo_festivo'] ?? 0),
                'recargo_espera' => (float)($viaje['recargo_espera'] ?? 0),
                'tiempo_espera_minutos' => (float)($viaje['tiempo_espera_min'] ?? 0),
                'subtotal_antes_minimo' => 0,
                'aplico_minimo' => false,
                'precio_final' => $precioReal,
                'comision_porcentaje' => $comisionPorcentaje,
                'comision_valor' => $comisionValor,
                'ganancia_conductor' => $gananciaViaje
            ];
            
            // Calcular subtotal
            $desglose['subtotal_antes_minimo'] = 
                $desglose['tarifa_base'] + 
                $desglose['precio_distancia'] + 
                $desglose['precio_tiempo'] + 
                $desglose['recargo_nocturno'] + 
                $desglose['recargo_hora_pico'] + 
                $desglose['recargo_festivo'] + 
                $desglose['recargo_espera'];
        }
        
        // Distancia y duración
        $distanciaKm = (float)($viaje['distancia_km'] ?? 0);
        $duracionSegundos = isset($viaje['duracion_segundos']) ? (int)round((float)$viaje['duracion_segundos']) : null;
        
        return [
            'id' => (int)$viaje['id'],
            'tipo_servicio' => $viaje['tipo_servicio'],
            'tipo_vehiculo' => $viaje['tipo_vehiculo'],
            'estado' => $viaje['estado'],
            // Distancia real
            'distancia_km' => round($distanciaKm, 2),
            'distancia_estimada' => $viaje['distancia_estimada'] ? (float)$viaje['distancia_estimada'] : null,
            // Duración
            'duracion_segundos' => $duracionSegundos,
            'duracion_minutos' => $duracionSegundos ? (int)ceil($duracionSegundos / 60) : null,
            'duracion_estimada' => $viaje['duracion_estimada'] ? (int)$viaje['duracion_estimada'] : null,
            // Fechas - Convertir a ISO8601 UTC para que el cliente convierta a hora local
            'fecha_solicitud' => to_iso8601($viaje['fecha_solicitud']),
            'fecha_completado' => to_iso8601($viaje['fecha_completado']),
            'fecha_aceptado' => to_iso8601($viaje['fecha_aceptado'] ?? null),
            // Ubicaciones
            'origen' => $viaje['origen'],
            'destino' => $viaje['destino'],
            // Cliente
            'cliente_nombre' => $viaje['cliente_nombre'],
            'cliente_apellido' => $viaje['cliente_apellido'],
            'calificacion' => $viaje['calificacion'] ? (int)$viaje['calificacion'] : null,
            'comentario' => $viaje['comentarios'],
            // Precios
            'precio_estimado' => round((float)($viaje['precio_estimado'] ?? 0), 0),
            'precio_final' => round($precioReal, 0),
            'metodo_pago' => $viaje['metodo_pago'] ?? 'efectivo',
            'pago_confirmado' => (bool)$viaje['pago_confirmado'],
            // DESGLOSE COMPLETO DEL PRECIO
            'desglose_precio' => $desglose,
            // Ganancias y comisiones (REALES)
            'comision_porcentaje' => round($comisionPorcentaje, 2),
            'comision_empresa' => round($comisionValor, 0),
            'ganancia_viaje' => round($gananciaViaje, 0)
        ];
    }, $viajes);

    echo json_encode([
        'success' => true,
        'viajes' => $viajes,
        'pagination' => [
            'page' => (int)$page,
            'limit' => (int)$limit,
            'total' => (int)$total,
            'total_pages' => (int)ceil($total / $limit)
        ],
        'message' => 'Historial obtenido exitosamente'
    ], JSON_NUMERIC_CHECK);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'viajes' => []
    ]);
}
?>
