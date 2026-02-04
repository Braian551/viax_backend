<?php
/**
 * API: Finalizar tracking y calcular precio final
 * Endpoint: conductor/tracking/finalize.php
 * Método: POST
 * 
 * Este endpoint se llama cuando el viaje termina para:
 * 1. Cerrar el tracking
 * 2. Calcular el precio final basado en distancia/tiempo REAL
 * 3. Calcular TODOS los recargos: nocturno, hora pico, festivo, espera
 * 4. Aplicar la comisión REAL de la empresa
 * 5. Actualizar la solicitud con los valores finales
 * 6. Retornar el desglose completo del precio
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once '../../config/database.php';

/**
 * Verifica si una fecha es festivo en Colombia
 * Lista básica de festivos fijos - se puede expandir
 */
function esFestivoColombia($fecha = null) {
    if ($fecha === null) {
        $fecha = new DateTime();
    }
    
    $mes = (int)$fecha->format('m');
    $dia = (int)$fecha->format('d');
    
    // Festivos fijos en Colombia
    $festivosFijos = [
        [1, 1],   // Año Nuevo
        [5, 1],   // Día del Trabajo
        [7, 20],  // Día de la Independencia
        [8, 7],   // Batalla de Boyacá
        [12, 8],  // Inmaculada Concepción
        [12, 25], // Navidad
    ];
    
    foreach ($festivosFijos as $festivo) {
        if ($mes === $festivo[0] && $dia === $festivo[1]) {
            return true;
        }
    }
    
    // Domingos también se consideran para recargo
    $diaSemana = (int)$fecha->format('w');
    return $diaSemana === 0; // 0 = Domingo
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar campos requeridos
    $solicitud_id = isset($input['solicitud_id']) ? intval($input['solicitud_id']) : 0;
    $conductor_id = isset($input['conductor_id']) ? intval($input['conductor_id']) : 0;
    
    // Valores finales del tracking (enviados por la app del conductor)
    $distancia_final_km = isset($input['distancia_final_km']) ? floatval($input['distancia_final_km']) : null;
    $tiempo_final_seg = isset($input['tiempo_final_seg']) ? intval($input['tiempo_final_seg']) : null;
    // Tiempo de espera adicional (si el cliente se demoró)
    $tiempo_espera_min = isset($input['tiempo_espera_min']) ? intval($input['tiempo_espera_min']) : 0;
    
    if ($solicitud_id <= 0 || $conductor_id <= 0) {
        throw new Exception('solicitud_id y conductor_id son requeridos');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    $db->beginTransaction();
    
    // Obtener datos del viaje
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.tipo_servicio,
            s.tipo_vehiculo,
            s.empresa_id,
            s.estado,
            s.distancia_estimada,
            s.tiempo_estimado,
            s.precio_estimado,
            s.distancia_recorrida,
            s.tiempo_transcurrido,
            s.solicitado_en
        FROM solicitudes_servicio s
        WHERE s.id = :solicitud_id
    ");
    $stmt->execute([':solicitud_id' => $solicitud_id]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$viaje) {
        throw new Exception('Viaje no encontrado');
    }
    
    // Obtener el último punto de tracking para valores más precisos
    $stmt = $db->prepare("
        SELECT 
            distancia_acumulada_km,
            tiempo_transcurrido_seg,
            precio_parcial,
            timestamp_gps
        FROM viaje_tracking_realtime
        WHERE solicitud_id = :solicitud_id
        ORDER BY timestamp_gps DESC
        LIMIT 1
    ");
    $stmt->execute([':solicitud_id' => $solicitud_id]);
    $ultimo_tracking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Usar valores del tracking si existen, si no, usar los enviados por la app
    if ($ultimo_tracking) {
        $distancia_real = floatval($ultimo_tracking['distancia_acumulada_km']);
        $tiempo_real_seg = $tiempo_final_seg ?? intval($ultimo_tracking['tiempo_transcurrido_seg']);
    } else {
        $distancia_real = $distancia_final_km ?? floatval($viaje['distancia_recorrida'] ?? 0);
        $tiempo_real_seg = $tiempo_final_seg ?? intval($viaje['tiempo_transcurrido'] ?? 0);
    }
    
    $tiempo_real_min = ceil($tiempo_real_seg / 60);
    
    // =====================================================
    // OBTENER CONFIGURACIÓN DE PRECIOS COMPLETA
    // =====================================================
    $empresa_id = $viaje['empresa_id'];
    $config = null;
    $config_precios_id = null;
    $comision_admin_porcentaje = 0; // Comisión que el admin cobra a la empresa
    
    // Obtener comisión del admin sobre la empresa (si aplica)
    if ($empresa_id) {
        $stmt = $db->prepare("SELECT comision_admin_porcentaje FROM empresas_transporte WHERE id = :id");
        $stmt->execute([':id' => $empresa_id]);
        $empresa_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($empresa_data) {
            $comision_admin_porcentaje = floatval($empresa_data['comision_admin_porcentaje'] ?? 0);
        }
    }
    
    // Primero buscar tarifa de la empresa
    if ($empresa_id) {
        $stmt = $db->prepare("
            SELECT 
                id,
                tarifa_base,
                costo_por_km,
                costo_por_minuto,
                tarifa_minima,
                tarifa_maxima,
                comision_plataforma,
                recargo_hora_pico,
                hora_pico_inicio_manana,
                hora_pico_fin_manana,
                hora_pico_inicio_tarde,
                hora_pico_fin_tarde,
                recargo_nocturno,
                hora_nocturna_inicio,
                hora_nocturna_fin,
                recargo_festivo,
                umbral_km_descuento,
                descuento_distancia_larga,
                tiempo_espera_gratis,
                costo_tiempo_espera
            FROM configuracion_precios 
            WHERE empresa_id = :empresa_id AND tipo_vehiculo = :tipo AND activo = 1
            LIMIT 1
        ");
        $stmt->execute([':empresa_id' => $empresa_id, ':tipo' => $viaje['tipo_vehiculo'] ?? 'moto']);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Si no hay tarifa de empresa, usar tarifa global
    if (!$config) {
        $stmt = $db->prepare("
            SELECT 
                id,
                tarifa_base,
                costo_por_km,
                costo_por_minuto,
                tarifa_minima,
                tarifa_maxima,
                comision_plataforma,
                recargo_hora_pico,
                hora_pico_inicio_manana,
                hora_pico_fin_manana,
                hora_pico_inicio_tarde,
                hora_pico_fin_tarde,
                recargo_nocturno,
                hora_nocturna_inicio,
                hora_nocturna_fin,
                recargo_festivo,
                umbral_km_descuento,
                descuento_distancia_larga,
                tiempo_espera_gratis,
                costo_tiempo_espera
            FROM configuracion_precios 
            WHERE empresa_id IS NULL AND tipo_vehiculo = :tipo AND activo = 1
            LIMIT 1
        ");
        $stmt->execute([':tipo' => $viaje['tipo_vehiculo'] ?? 'moto']);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$config) {
        throw new Exception('No hay configuración de precios para este tipo de vehículo');
    }
    
    $config_precios_id = intval($config['id']);
    
    // =====================================================
    // CALCULAR PRECIO FINAL CON TODOS LOS COMPONENTES
    // =====================================================
    
    // 1. Componentes base
    $tarifa_base = floatval($config['tarifa_base']);
    $precio_distancia = $distancia_real * floatval($config['costo_por_km']);
    $precio_tiempo = $tiempo_real_min * floatval($config['costo_por_minuto']);
    
    $subtotal_sin_recargos = $tarifa_base + $precio_distancia + $precio_tiempo;
    
    // 2. Descuento por distancia larga
    $descuento_distancia_larga = 0;
    $umbral_km = floatval($config['umbral_km_descuento'] ?? 15);
    if ($distancia_real >= $umbral_km) {
        $descuento_distancia_larga = $subtotal_sin_recargos * (floatval($config['descuento_distancia_larga'] ?? 0) / 100);
    }
    
    $subtotal_con_descuento = $subtotal_sin_recargos - $descuento_distancia_larga;
    
    // 3. Tiempo de espera (fuera del tiempo gratis)
    $tiempo_espera_gratis = intval($config['tiempo_espera_gratis'] ?? 3);
    $tiempo_espera_cobrable = max(0, $tiempo_espera_min - $tiempo_espera_gratis);
    $recargo_espera = $tiempo_espera_cobrable * floatval($config['costo_tiempo_espera'] ?? 0);
    
    // 4. Determinar recargos por horario/fecha
    $hora_actual = date('H:i:s');
    $fecha_actual = new DateTime();
    
    $recargo_nocturno = 0;
    $recargo_hora_pico = 0;
    $recargo_festivo = 0;
    $tipo_recargo = 'normal';
    $recargo_porcentaje = 0;
    
    // Verificar si es festivo primero (tiene prioridad)
    $es_festivo = esFestivoColombia($fecha_actual);
    
    if ($es_festivo && floatval($config['recargo_festivo'] ?? 0) > 0) {
        // Recargo festivo
        $recargo_porcentaje = floatval($config['recargo_festivo']);
        $recargo_festivo = $subtotal_con_descuento * ($recargo_porcentaje / 100);
        $tipo_recargo = 'festivo';
    } else {
        // Verificar hora pico o nocturno
        $h_pico_ini_m = $config['hora_pico_inicio_manana'] ?? '07:00:00';
        $h_pico_fin_m = $config['hora_pico_fin_manana'] ?? '09:00:00';
        $h_pico_ini_t = $config['hora_pico_inicio_tarde'] ?? '17:00:00';
        $h_pico_fin_t = $config['hora_pico_fin_tarde'] ?? '19:00:00';
        $h_noc_ini = $config['hora_nocturna_inicio'] ?? '22:00:00';
        $h_noc_fin = $config['hora_nocturna_fin'] ?? '06:00:00';
        
        // Hora pico mañana
        if ($hora_actual >= $h_pico_ini_m && $hora_actual <= $h_pico_fin_m) {
            $recargo_porcentaje = floatval($config['recargo_hora_pico'] ?? 0);
            $recargo_hora_pico = $subtotal_con_descuento * ($recargo_porcentaje / 100);
            $tipo_recargo = 'hora_pico_manana';
        }
        // Hora pico tarde
        elseif ($hora_actual >= $h_pico_ini_t && $hora_actual <= $h_pico_fin_t) {
            $recargo_porcentaje = floatval($config['recargo_hora_pico'] ?? 0);
            $recargo_hora_pico = $subtotal_con_descuento * ($recargo_porcentaje / 100);
            $tipo_recargo = 'hora_pico_tarde';
        }
        // Nocturno (cruza medianoche)
        elseif ($hora_actual >= $h_noc_ini || $hora_actual <= $h_noc_fin) {
            $recargo_porcentaje = floatval($config['recargo_nocturno'] ?? 0);
            $recargo_nocturno = $subtotal_con_descuento * ($recargo_porcentaje / 100);
            $tipo_recargo = 'nocturno';
        }
    }
    
    // 5. Sumar todos los recargos
    $total_recargos = $recargo_nocturno + $recargo_hora_pico + $recargo_festivo + $recargo_espera;
    
    // 6. Precio total antes de límites
    $precio_total = $subtotal_con_descuento + $total_recargos;
    
    // 7. Aplicar tarifa mínima
    $tarifa_minima = floatval($config['tarifa_minima']);
    $aplico_tarifa_minima = false;
    if ($precio_total < $tarifa_minima) {
        $precio_total = $tarifa_minima;
        $aplico_tarifa_minima = true;
    }
    
    // 8. Aplicar tarifa máxima si existe
    if ($config['tarifa_maxima'] !== null && $config['tarifa_maxima'] > 0) {
        $tarifa_maxima = floatval($config['tarifa_maxima']);
        if ($precio_total > $tarifa_maxima) {
            $precio_total = $tarifa_maxima;
        }
    }
    
    // 9. Redondear a 100 COP más cercano (típico en Colombia)
    $precio_final = round($precio_total / 100) * 100;
    
    // 10. Calcular comisión de la EMPRESA al conductor
    // Esta es la comisión que la empresa cobra a sus conductores
    $comision_plataforma_porcentaje = floatval($config['comision_plataforma']);
    $comision_plataforma_valor = $precio_final * ($comision_plataforma_porcentaje / 100);
    $ganancia_conductor = $precio_final - $comision_plataforma_valor;
    
    // 11. Calcular comisión del ADMIN sobre lo que gana la empresa
    // Esta es la comisión que el admin (VIAX) cobra a las empresas de transporte
    // Se calcula sobre la comisión que la empresa cobró al conductor
    $comision_admin_valor = $comision_plataforma_valor * ($comision_admin_porcentaje / 100);
    $ganancia_empresa = $comision_plataforma_valor - $comision_admin_valor;
    
    // =====================================================
    // DETECTAR DESVÍOS SIGNIFICATIVOS
    // =====================================================
    
    $distancia_estimada = floatval($viaje['distancia_estimada']);
    $diferencia_distancia = $distancia_real - $distancia_estimada;
    $porcentaje_desvio = $distancia_estimada > 0 
        ? ($diferencia_distancia / $distancia_estimada) * 100 
        : 0;
    
    $tuvo_desvio = abs($porcentaje_desvio) > 20;
    
    // =====================================================
    // CREAR OBJETO JSON DE DESGLOSE COMPLETO
    // =====================================================
    
    $desglose_json = json_encode([
        'tarifa_base' => round($tarifa_base, 2),
        'precio_distancia' => round($precio_distancia, 2),
        'precio_tiempo' => round($precio_tiempo, 2),
        'subtotal_sin_recargos' => round($subtotal_sin_recargos, 2),
        'descuento_distancia_larga' => round($descuento_distancia_larga, 2),
        'subtotal_con_descuento' => round($subtotal_con_descuento, 2),
        'recargo_nocturno' => round($recargo_nocturno, 2),
        'recargo_hora_pico' => round($recargo_hora_pico, 2),
        'recargo_festivo' => round($recargo_festivo, 2),
        'recargo_espera' => round($recargo_espera, 2),
        'tiempo_espera_min' => $tiempo_espera_cobrable,
        'total_recargos' => round($total_recargos, 2),
        'tipo_recargo' => $tipo_recargo,
        'recargo_porcentaje' => $recargo_porcentaje,
        'aplico_tarifa_minima' => $aplico_tarifa_minima,
        'precio_antes_redondeo' => round($precio_total, 2),
        'precio_final' => $precio_final,
        // Comisión de la empresa al conductor
        'comision_plataforma_porcentaje' => $comision_plataforma_porcentaje,
        'comision_plataforma_valor' => round($comision_plataforma_valor, 2),
        'ganancia_conductor' => round($ganancia_conductor, 2),
        // Comisión del admin a la empresa
        'comision_admin_porcentaje' => $comision_admin_porcentaje,
        'comision_admin_valor' => round($comision_admin_valor, 2),
        'ganancia_empresa' => round($ganancia_empresa, 2),
        // Datos del viaje
        'distancia_km' => round($distancia_real, 2),
        'tiempo_min' => $tiempo_real_min,
        'config_precios_id' => $config_precios_id,
        'empresa_id' => $empresa_id
    ]);
    
    // =====================================================
    // ACTUALIZAR RESUMEN DE TRACKING CON DESGLOSE COMPLETO
    // =====================================================
    
    $tiempo_estimado_min = intval($viaje['tiempo_estimado']);
    $diff_tiempo_min = $tiempo_real_min - $tiempo_estimado_min;
    
    $stmt = $db->prepare("
        INSERT INTO viaje_resumen_tracking (
            solicitud_id,
            distancia_real_km,
            tiempo_real_minutos,
            distancia_estimada_km,
            tiempo_estimado_minutos,
            diferencia_distancia_km,
            diferencia_tiempo_min,
            porcentaje_desvio_distancia,
            precio_estimado,
            precio_final_calculado,
            precio_final_aplicado,
            tiene_desvio_ruta,
            fin_viaje_real,
            actualizado_en,
            -- Nuevas columnas de desglose
            tarifa_base,
            precio_distancia,
            precio_tiempo,
            recargo_nocturno,
            recargo_hora_pico,
            recargo_festivo,
            recargo_espera,
            tiempo_espera_min,
            descuento_distancia_larga,
            subtotal_sin_recargos,
            total_recargos,
            tipo_recargo,
            aplico_tarifa_minima,
            -- Comisión empresa al conductor
            comision_plataforma_porcentaje,
            comision_plataforma_valor,
            ganancia_conductor,
            -- Comisión admin a la empresa
            comision_admin_porcentaje,
            comision_admin_valor,
            ganancia_empresa,
            -- Referencias
            empresa_id,
            config_precios_id
        ) VALUES (
            :solicitud_id,
            :distancia_real,
            :tiempo_real,
            :distancia_estimada,
            :tiempo_estimado,
            :diff_distancia,
            :diff_tiempo,
            :porcentaje_desvio,
            :precio_estimado,
            :precio_calculado,
            :precio_aplicado,
            :tuvo_desvio,
            NOW(),
            NOW(),
            :tarifa_base,
            :precio_distancia,
            :precio_tiempo,
            :recargo_nocturno,
            :recargo_hora_pico,
            :recargo_festivo,
            :recargo_espera,
            :tiempo_espera_cobrable,
            :descuento_distancia,
            :subtotal_sin_recargos,
            :total_recargos,
            :tipo_recargo,
            :aplico_tarifa_minima,
            :comision_porcentaje,
            :comision_valor,
            :ganancia_conductor,
            :comision_admin_porcentaje,
            :comision_admin_valor,
            :ganancia_empresa,
            :empresa_id,
            :config_precios_id
        )
        ON CONFLICT (solicitud_id) DO UPDATE SET
            distancia_real_km = EXCLUDED.distancia_real_km,
            tiempo_real_minutos = EXCLUDED.tiempo_real_minutos,
            distancia_estimada_km = EXCLUDED.distancia_estimada_km,
            tiempo_estimado_minutos = EXCLUDED.tiempo_estimado_minutos,
            diferencia_distancia_km = EXCLUDED.diferencia_distancia_km,
            diferencia_tiempo_min = EXCLUDED.diferencia_tiempo_min,
            porcentaje_desvio_distancia = EXCLUDED.porcentaje_desvio_distancia,
            precio_estimado = EXCLUDED.precio_estimado,
            precio_final_calculado = EXCLUDED.precio_final_calculado,
            precio_final_aplicado = EXCLUDED.precio_final_aplicado,
            tiene_desvio_ruta = EXCLUDED.tiene_desvio_ruta,
            fin_viaje_real = NOW(),
            actualizado_en = NOW(),
            tarifa_base = EXCLUDED.tarifa_base,
            precio_distancia = EXCLUDED.precio_distancia,
            precio_tiempo = EXCLUDED.precio_tiempo,
            recargo_nocturno = EXCLUDED.recargo_nocturno,
            recargo_hora_pico = EXCLUDED.recargo_hora_pico,
            recargo_festivo = EXCLUDED.recargo_festivo,
            recargo_espera = EXCLUDED.recargo_espera,
            tiempo_espera_min = EXCLUDED.tiempo_espera_min,
            descuento_distancia_larga = EXCLUDED.descuento_distancia_larga,
            subtotal_sin_recargos = EXCLUDED.subtotal_sin_recargos,
            total_recargos = EXCLUDED.total_recargos,
            tipo_recargo = EXCLUDED.tipo_recargo,
            aplico_tarifa_minima = EXCLUDED.aplico_tarifa_minima,
            comision_plataforma_porcentaje = EXCLUDED.comision_plataforma_porcentaje,
            comision_plataforma_valor = EXCLUDED.comision_plataforma_valor,
            ganancia_conductor = EXCLUDED.ganancia_conductor,
            comision_admin_porcentaje = EXCLUDED.comision_admin_porcentaje,
            comision_admin_valor = EXCLUDED.comision_admin_valor,
            ganancia_empresa = EXCLUDED.ganancia_empresa,
            empresa_id = EXCLUDED.empresa_id,
            config_precios_id = EXCLUDED.config_precios_id
    ");
    
    $stmt->execute([
        ':solicitud_id' => $solicitud_id,
        ':distancia_real' => $distancia_real,
        ':tiempo_real' => $tiempo_real_min,
        ':distancia_estimada' => $distancia_estimada,
        ':tiempo_estimado' => $tiempo_estimado_min,
        ':diff_distancia' => $diferencia_distancia,
        ':diff_tiempo' => $diff_tiempo_min,
        ':porcentaje_desvio' => $porcentaje_desvio,
        ':precio_estimado' => floatval($viaje['precio_estimado']),
        ':precio_calculado' => $precio_final,
        ':precio_aplicado' => $precio_final,
        ':tuvo_desvio' => $tuvo_desvio ? 1 : 0,
        ':tarifa_base' => $tarifa_base,
        ':precio_distancia' => $precio_distancia,
        ':precio_tiempo' => $precio_tiempo,
        ':recargo_nocturno' => $recargo_nocturno,
        ':recargo_hora_pico' => $recargo_hora_pico,
        ':recargo_festivo' => $recargo_festivo,
        ':recargo_espera' => $recargo_espera,
        ':tiempo_espera_cobrable' => $tiempo_espera_cobrable,
        ':descuento_distancia' => $descuento_distancia_larga,
        ':subtotal_sin_recargos' => $subtotal_sin_recargos,
        ':total_recargos' => $total_recargos,
        ':tipo_recargo' => $tipo_recargo,
        ':aplico_tarifa_minima' => $aplico_tarifa_minima ? 1 : 0,
        ':comision_porcentaje' => $comision_plataforma_porcentaje,
        ':comision_valor' => $comision_plataforma_valor,
        ':ganancia_conductor' => $ganancia_conductor,
        ':comision_admin_porcentaje' => $comision_admin_porcentaje,
        ':comision_admin_valor' => $comision_admin_valor,
        ':ganancia_empresa' => $ganancia_empresa,
        ':empresa_id' => $empresa_id,
        ':config_precios_id' => $config_precios_id
    ]);
    
    // =====================================================
    // ACTUALIZAR SALDO PENDIENTE DE LA EMPRESA CON ADMIN
    // =====================================================
    // La empresa debe al admin la comision_admin_valor de cada viaje
    // MODIFICACION: Se comenta esto porque el cobro se hace ahora al REGISTRAR EL PAGO del conductor.
    /*
    if ($empresa_id && $comision_admin_valor > 0) {
        // Obtener saldo actual de la empresa
        $stmtSaldo = $db->prepare("SELECT saldo_pendiente FROM empresas_transporte WHERE id = :id FOR UPDATE");
        $stmtSaldo->execute([':id' => $empresa_id]);
        $saldo_actual = floatval($stmtSaldo->fetchColumn() ?? 0);
        $nuevo_saldo = $saldo_actual + $comision_admin_valor;
        
        // Actualizar saldo pendiente
        $stmtUpdate = $db->prepare("UPDATE empresas_transporte SET saldo_pendiente = :nuevo_saldo, actualizado_en = NOW() WHERE id = :id");
        $stmtUpdate->execute([':nuevo_saldo' => $nuevo_saldo, ':id' => $empresa_id]);
        
        // Registrar movimiento en pagos_empresas (cargo por comisión del viaje)
        $stmtMovimiento = $db->prepare("
            INSERT INTO pagos_empresas (empresa_id, monto, tipo, descripcion, viaje_id, saldo_anterior, saldo_nuevo, creado_en)
            VALUES (:empresa_id, :monto, 'cargo', :descripcion, :viaje_id, :saldo_anterior, :saldo_nuevo, NOW())
        ");
        $stmtMovimiento->execute([
            ':empresa_id' => $empresa_id,
            ':monto' => $comision_admin_valor,
            ':descripcion' => "Comisión viaje #$solicitud_id ({$comision_admin_porcentaje}%)",
            ':viaje_id' => $solicitud_id,
            ':saldo_anterior' => $saldo_actual,
            ':saldo_nuevo' => $nuevo_saldo
        ]);
    }
    */
    
    // =====================================================
    // ACTUALIZAR SOLICITUD CON DESGLOSE JSON
    // =====================================================
    
    $stmt = $db->prepare("
        UPDATE solicitudes_servicio SET
            pago_confirmado = true,
            pago_confirmado_en = NOW(),
            precio_final = :precio_final,
            distancia_recorrida = :distancia,
            tiempo_transcurrido = :tiempo,
            precio_ajustado_por_tracking = TRUE,
            tuvo_desvio_ruta = :tuvo_desvio,
            desglose_precio = :desglose_json
        WHERE id = :solicitud_id
    ");
    
    $stmt->execute([
        ':precio_final' => $precio_final,
        ':distancia' => $distancia_real,
        ':tiempo' => $tiempo_real_seg,
        ':tuvo_desvio' => $tuvo_desvio ? 1 : 0,
        ':desglose_json' => $desglose_json,
        ':solicitud_id' => $solicitud_id
    ]);
    
    $db->commit();
    
    // =====================================================
    // RESPUESTA CON DESGLOSE COMPLETO
    // =====================================================
    
    $response = [
        'success' => true,
        'message' => 'Tracking finalizado y precio calculado',
        'precio_final' => $precio_final,
        'desglose' => [
            'tarifa_base' => round($tarifa_base, 2),
            'precio_distancia' => round($precio_distancia, 2),
            'precio_tiempo' => round($precio_tiempo, 2),
            'subtotal_sin_recargos' => round($subtotal_sin_recargos, 2),
            'descuento_distancia_larga' => round($descuento_distancia_larga, 2),
            'recargo_nocturno' => round($recargo_nocturno, 2),
            'recargo_hora_pico' => round($recargo_hora_pico, 2),
            'recargo_festivo' => round($recargo_festivo, 2),
            'recargo_espera' => round($recargo_espera, 2),
            'tiempo_espera_min' => $tiempo_espera_cobrable,
            'total_recargos' => round($total_recargos, 2),
            'tipo_recargo' => $tipo_recargo,
            'recargo_porcentaje' => $recargo_porcentaje,
            'aplico_tarifa_minima' => $aplico_tarifa_minima,
            'precio_antes_redondeo' => round($precio_total, 2),
            'precio_final' => $precio_final
        ],
        'tracking' => [
            'distancia_real_km' => round($distancia_real, 2),
            'tiempo_real_min' => $tiempo_real_min,
            'tiempo_real_seg' => $tiempo_real_seg,
            'distancia_estimada_km' => $distancia_estimada,
            'tiempo_estimado_min' => $tiempo_estimado_min
        ],
        'diferencias' => [
            'diferencia_distancia_km' => round($diferencia_distancia, 2),
            'diferencia_tiempo_min' => $diff_tiempo_min,
            'porcentaje_desvio' => round($porcentaje_desvio, 1),
            'tuvo_desvio_significativo' => $tuvo_desvio
        ],
        'comisiones' => [
            'comision_plataforma_porcentaje' => $comision_plataforma_porcentaje,
            'comision_plataforma_valor' => round($comision_plataforma_valor, 2),
            'ganancia_conductor' => round($ganancia_conductor, 2)
        ],
        'comparacion_precio' => [
            'precio_estimado' => floatval($viaje['precio_estimado']),
            'precio_final' => $precio_final,
            'diferencia' => $precio_final - floatval($viaje['precio_estimado'])
        ],
        'meta' => [
            'empresa_id' => $empresa_id,
            'config_precios_id' => $config_precios_id,
            'tipo_vehiculo' => $viaje['tipo_vehiculo'] ?? 'moto'
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
