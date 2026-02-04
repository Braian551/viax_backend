<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $solicitud_id = $input['solicitud_id'] ?? null;
    $conductor_id = $input['conductor_id'] ?? null;
    $nuevo_estado = $input['nuevo_estado'] ?? null;
    
    if (!$solicitud_id || !$conductor_id || !$nuevo_estado) {
        throw new Exception('solicitud_id, conductor_id y nuevo_estado son requeridos');
    }
    
    // Validar estados permitidos
    $estados_validos = ['conductor_llego', 'recogido', 'en_curso', 'completada', 'cancelada'];
    if (!in_array($nuevo_estado, $estados_validos)) {
        throw new Exception('Estado no válido');
    }

    // Preparar campos opcionales
    $distancia_recorrida = isset($input['distancia_recorrida']) ? floatval($input['distancia_recorrida']) : null;
    $tiempo_transcurrido = isset($input['tiempo_transcurrido']) ? intval($input['tiempo_transcurrido']) : null;
    $motivo_cancelacion = isset($input['motivo_cancelacion']) ? $input['motivo_cancelacion'] : null;
    $precio_final = isset($input['precio_final']) ? floatval($input['precio_final']) : null;

    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar que el conductor está asignado a esta solicitud
    $stmt = $db->prepare("
        SELECT s.*, ac.conductor_id 
        FROM solicitudes_servicio s
        LEFT JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id AND ac.estado IN ('asignado', 'llegado', 'en_curso', 'completado')
        WHERE s.id = ?
    ");
    $stmt->execute([$solicitud_id]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitud) {
        throw new Exception('Solicitud no encontrada');
    }
    
    if ($solicitud['conductor_id'] && $solicitud['conductor_id'] != $conductor_id) {
        throw new Exception('No tienes permiso para actualizar esta solicitud');
    }
    
    // Construir QUERY dinámico
    $update_fields = ["estado = :estado"];
    $params = [
        ':estado' => $nuevo_estado,
        ':solicitud_id' => $solicitud_id
    ];

    // Actualizar timestamps y estados
    if ($nuevo_estado === 'conductor_llego') {
        $update_fields[] = "conductor_llego_en = NOW()";
        
        // Actualizar asignación
        $stmtAsig = $db->prepare("UPDATE asignaciones_conductor SET estado = 'llegado' WHERE solicitud_id = ? AND conductor_id = ?");
        $stmtAsig->execute([$solicitud_id, $conductor_id]);
        
    } elseif ($nuevo_estado === 'recogido' || $nuevo_estado === 'en_curso') {
        $update_fields[] = "recogido_en = NOW()";
        
        // Actualizar asignación a 'en_curso'
        $stmtAsig = $db->prepare("UPDATE asignaciones_conductor SET estado = 'en_curso' WHERE solicitud_id = ? AND conductor_id = ?");
        $stmtAsig->execute([$solicitud_id, $conductor_id]);
        
    } elseif ($nuevo_estado === 'completada') {
        $update_fields[] = "completado_en = NOW()";
        $update_fields[] = "entregado_en = NOW()";
        
        if ($precio_final !== null) {
            $update_fields[] = "precio_final = :precio";
            $params[':precio'] = $precio_final;
        }
    } elseif ($nuevo_estado === 'cancelada') {
        $update_fields[] = "cancelado_en = NOW()";
        if ($motivo_cancelacion) {
            $update_fields[] = "motivo_cancelacion = :motivo";
            $params[':motivo'] = $motivo_cancelacion;
        }
    }

    // --- GUARDAR DATOS FINALES DEL VIAJE ---
    // Siempre guardar distancia y tiempo si vienen en la petición
    if ($distancia_recorrida !== null && $distancia_recorrida > 0) {
        $update_fields[] = "distancia_recorrida = :distancia";
        $params[':distancia'] = $distancia_recorrida;
    }
    if ($tiempo_transcurrido !== null && $tiempo_transcurrido > 0) {
        $update_fields[] = "tiempo_transcurrido = :tiempo";
        $params[':tiempo'] = $tiempo_transcurrido;
    }

    $query = "UPDATE solicitudes_servicio SET " . implode(', ', $update_fields) . " WHERE id = :solicitud_id";
    $stmt = $db->prepare($query);
    $stmt->execute($params);


    // Si se completó el viaje, actualizar disponibilidad, asignación y asegurar inmutabilidad de la comisión
    if ($nuevo_estado === 'completada') {
        $stmt = $db->prepare("
            UPDATE detalles_conductor 
            SET disponible = 1,
                total_viajes = COALESCE(total_viajes, 0) + 1
            WHERE usuario_id = ?
        ");
        $stmt->execute([$conductor_id]);
        
        // Actualizar estado de la asignación a 'completado'
        $stmt = $db->prepare("UPDATE asignaciones_conductor SET estado = 'completado' WHERE solicitud_id = ? AND conductor_id = ?");
        $stmt->execute([$solicitud_id, $conductor_id]);
        
        // --- LOGICA DE COMISIÓN (OPTIMIZACIÓN PARA INMUTABILIDAD) ---
        // Verificar si ya existe un tracking finalizado (creado por finalize.php)
        $stmtCheck = $db->prepare("SELECT id FROM viaje_resumen_tracking WHERE solicitud_id = ?");
        $stmtCheck->execute([$solicitud_id]);
        if (!$stmtCheck->fetch()) {
            // Si no existe, creamos un registro básico para "congelar" la comisión y el precio
            // Esto asegura que cambios futuros en tarifas NO afecten viajes pasados
            
            $precioViaje = $precio_final ?? floatval($solicitud['precio_estimado'] ?? 0);
            
            // Obtener configuración de precios vigente
            $empresaId = $solicitud['empresa_id'] ?? null;
            $tipoVehiculo = $solicitud['tipo_vehiculo'] ?? 'moto';
            
            $queryConfig = "SELECT comision_plataforma, comision_metodo_pago, id as config_id 
                           FROM configuracion_precios 
                           WHERE tipo_vehiculo = :tipo AND activo = 1";
                           
            if ($empresaId) {
                $queryConfig .= " AND empresa_id = :empresa_id";
                $stmtConfig = $db->prepare($queryConfig);
                $stmtConfig->execute([':tipo' => $tipoVehiculo, ':empresa_id' => $empresaId]);
            } else {
                $queryConfig .= " AND empresa_id IS NULL";
                $stmtConfig = $db->prepare($queryConfig);
                $stmtConfig->execute([':tipo' => $tipoVehiculo]);
            }
            
            $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);
            
            // Valores por defecto si no hay config (fallback)
            $porcentajeComision = 15.0; // Default
            $configId = null;
            
            if ($config) {
                $porcentajeComision = floatval($config['comision_plataforma']);
                $configId = $config['config_id'];
            }
            
            $valorComision = $precioViaje * ($porcentajeComision / 100);
            $gananciaConductor = $precioViaje - $valorComision;
            
            // Insertar tracking "dummy" para fines financieros
            $stmtInsert = $db->prepare("
                INSERT INTO viaje_resumen_tracking (
                    solicitud_id,
                    precio_final_aplicado,
                    comision_plataforma_porcentaje,
                    comision_plataforma_valor,
                    ganancia_conductor,
                    config_precios_id,
                    empresa_id,
                    actualizado_en,
                    fin_viaje_real
                ) VALUES (
                    :solid, :precio, :porc, :valor, :ganancia, :confid, :empid, NOW(), NOW()
                )
            ");
            
            $stmtInsert->execute([
                ':solid' => $solicitud_id,
                ':precio' => $precioViaje,
                ':porc' => $porcentajeComision,
                ':valor' => $valorComision,
                ':ganancia' => $gananciaConductor,
                ':confid' => $configId,
                ':empid' => $empresaId
            ]);
        }

        // Actualizar métricas de la empresa del conductor
        $stmtEmpresa = $db->prepare("SELECT empresa_id FROM usuarios WHERE id = ?");
        $stmtEmpresa->execute([$conductor_id]);
        $empresaIdConductor = $stmtEmpresa->fetchColumn();
        
        if ($empresaIdConductor) {
            // Actualizar total_viajes_completados e ingresos en empresas_metricas
            $precioViaje = $precio_final ?? $solicitud['precio_estimado'] ?? 0;
            
            $stmt = $db->prepare("
                INSERT INTO empresas_metricas (empresa_id, total_viajes_completados, ingresos_totales, viajes_mes, ingresos_mes, ultima_actualizacion)
                VALUES (?, 1, ?, 1, ?, NOW())
                ON CONFLICT (empresa_id) DO UPDATE SET
                    total_viajes_completados = empresas_metricas.total_viajes_completados + 1,
                    ingresos_totales = empresas_metricas.ingresos_totales + EXCLUDED.ingresos_totales,
                    viajes_mes = empresas_metricas.viajes_mes + 1,
                    ingresos_mes = empresas_metricas.ingresos_mes + EXCLUDED.ingresos_mes,
                    ultima_actualizacion = NOW()
            ");
            $stmt->execute([$empresaIdConductor, $precioViaje, $precioViaje]);
        }
    }
    
    // Si se canceló, liberar al conductor
    if ($nuevo_estado === 'cancelada') {
        $stmt = $db->prepare("UPDATE detalles_conductor SET disponible = 1 WHERE usuario_id = ?");
        $stmt->execute([$conductor_id]);
        
        $stmt = $db->prepare("UPDATE asignaciones_conductor SET estado = 'cancelado' WHERE solicitud_id = ? AND conductor_id = ?");
        $stmt->execute([$solicitud_id, $conductor_id]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Estado actualizado correctamente',
        'nuevo_estado' => $nuevo_estado
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
