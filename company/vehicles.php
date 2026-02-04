<?php
/**
 * Company Vehicles API - Sistema Normalizado
 * 
 * Gestiona los tipos de vehículo habilitados por empresa con:
 * - Estados activo/inactivo
 * - Historial de cambios
 * - Notificaciones a conductores afectados
 * 
 * GET    - Obtener tipos de vehículo de la empresa
 * POST   - Activar/desactivar tipo de vehículo
 * 
 * @author Viax Team
 * @version 2.0
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable display_errors to return clean JSON
ini_set('log_errors', 1);

require_once '../config/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? null;
    
    if (!$method) {
        // Script was included, do nothing (for testing)
    } else {
        if ($method === 'GET') {
            $input = $_GET;
        } else {
            $input = getJsonInput();
        }

        $empresaId = isset($input['empresa_id']) ? intval($input['empresa_id']) : null;
        
        if (!$empresaId) {
            sendJsonResponse(false, 'Falta parámetro empresa_id');
            exit();
        }

        switch ($method) {
            case 'GET':
                handleGetVehicles($db, $empresaId);
                break;
            case 'POST':
                handleToggleVehicle($db, $input, $empresaId);
                break;
            default:
                sendJsonResponse(false, 'Método no permitido');
        }
    }

} catch (Exception $e) {
    error_log("Error in company/vehicles.php: " . $e->getMessage());
    sendJsonResponse(false, 'Error del servidor: ' . $e->getMessage());
}

/**
 * Verifica si una tabla existe en la base de datos
 */
function checkTableExists($db, $tableName) {
    try {
        $query = "SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_name = ?
        )";
        $stmt = $db->prepare($query);
        $stmt->execute([$tableName]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Obtiene los tipos de vehículo con su estado para una empresa
 */
function handleGetVehicles($db, $empresaId) {
    try {
        // Primero verificar si existen las nuevas tablas
        $tableExists = checkTableExists($db, 'empresa_tipos_vehiculo');
        
        if ($tableExists) {
            // Usar nueva estructura normalizada
            $query = "SELECT 
                        etv.tipo_vehiculo_codigo as codigo,
                        COALESCE(ctv.nombre, etv.tipo_vehiculo_codigo) as nombre,
                        COALESCE(ctv.descripcion, '') as descripcion,
                        COALESCE(ctv.icono, '') as icono,
                        etv.activo,
                        etv.fecha_activacion,
                        etv.fecha_desactivacion,
                        etv.conductores_activos,
                        etv.motivo_desactivacion
                      FROM empresa_tipos_vehiculo etv
                      LEFT JOIN catalogo_tipos_vehiculo ctv ON etv.tipo_vehiculo_codigo = ctv.codigo
                      WHERE etv.empresa_id = ?
                      ORDER BY COALESCE(ctv.orden, 999), etv.tipo_vehiculo_codigo";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$empresaId]);
            $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Lista simple de códigos activos para compatibilidad
            $vehiculosActivos = array_column(
                array_filter($tipos, fn($t) => $t['activo']), 
                'codigo'
            );
            
            sendJsonResponse(true, 'Vehículos obtenidos', [
                'vehiculos' => $vehiculosActivos,
                'tipos_empresa' => $tipos,
                'usa_tabla_normalizada' => true
            ]);
            
        } else {
            // Fallback: usar configuracion_precios (compatibilidad)
            $query = "SELECT DISTINCT tipo_vehiculo FROM configuracion_precios 
                      WHERE empresa_id = ? AND activo = 1";
            $stmt = $db->prepare($query);
            $stmt->execute([$empresaId]);
            $vehicles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            sendJsonResponse(true, 'Vehículos obtenidos', [
                'vehiculos' => $vehicles,
                'usa_tabla_normalizada' => false
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Error handleGetVehicles: " . $e->getMessage());
        sendJsonResponse(false, 'Error al leer vehículos: ' . $e->getMessage());
    }
}

/**
 * Activa o desactiva un tipo de vehículo para la empresa
 */
function handleToggleVehicle($db, $input, $empresaId) {
    try {
        $tipoVehiculo = $input['tipo_vehiculo'] ?? null;
        $activo = isset($input['activo']) ? (bool)$input['activo'] : true;
        $usuarioId = $input['usuario_id'] ?? null;
        $motivo = $input['motivo'] ?? null;
        
        if (!$tipoVehiculo) {
            sendJsonResponse(false, 'Se requiere tipo_vehiculo');
            return;
        }
        
        // Validar tipo de vehículo
        $tiposValidos = ['moto', 'motocarro', 'taxi', 'carro'];
        if (!in_array($tipoVehiculo, $tiposValidos)) {
            sendJsonResponse(false, 'Tipo de vehículo inválido');
            return;
        }
        
        $tableExists = checkTableExists($db, 'empresa_tipos_vehiculo');
        
        $db->beginTransaction();
        
        $conductoresAfectados = 0;
        $conductores = [];
        
        if ($tableExists) {
            // Usar nueva estructura normalizada
            $result = toggleVehicleNormalizado($db, $empresaId, $tipoVehiculo, $activo, $usuarioId, $motivo);
            $conductoresAfectados = $result['conductores_afectados'];
            $conductores = $result['conductores'];
        } else {
            // Fallback: usar configuracion_precios
            toggleVehicleLegacy($db, $empresaId, $tipoVehiculo, $activo);
        }
        
        $db->commit();
        
        // Si se desactivó, notificar a conductores afectados
        if (!$activo && $conductoresAfectados > 0) {
            try {
                notificarConductoresAfectados($db, $empresaId, $tipoVehiculo, $conductores);
            } catch (Exception $e) {
                error_log("Error enviando notificaciones: " . $e->getMessage());
            }
        }
        
        sendJsonResponse(true, $activo ? 'Vehículo habilitado' : 'Vehículo deshabilitado', [
            'tipo_vehiculo' => $tipoVehiculo,
            'activo' => $activo,
            'conductores_afectados' => $conductoresAfectados
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error Toggle Vehicle: " . $e->getMessage());
        sendJsonResponse(false, 'Error: ' . $e->getMessage());
    }
}

/**
 * Toggle usando la nueva tabla normalizada
 */
function toggleVehicleNormalizado($db, $empresaId, $tipoVehiculo, $activo, $usuarioId, $motivo) {
    $conductoresAfectados = 0;
    $conductores = [];
    
    // Verificar si existe el registro
    $check = "SELECT id, activo FROM empresa_tipos_vehiculo WHERE empresa_id = ? AND tipo_vehiculo_codigo = ?";
    $stmtCheck = $db->prepare($check);
    $stmtCheck->execute([$empresaId, $tipoVehiculo]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Actualizar registro existente
        if ($activo) {
            $sql = "UPDATE empresa_tipos_vehiculo 
                    SET activo = true, 
                        fecha_activacion = NOW(),
                        activado_por = ?,
                        fecha_desactivacion = NULL,
                        desactivado_por = NULL,
                        motivo_desactivacion = NULL
                    WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$usuarioId, $existing['id']]);
        } else {
            // Obtener conductores afectados ANTES de desactivar
            $conductores = getConductoresAfectados($db, $empresaId, $tipoVehiculo);
            $conductoresAfectados = count($conductores);
            
            $sql = "UPDATE empresa_tipos_vehiculo 
                    SET activo = false,
                        fecha_desactivacion = NOW(),
                        desactivado_por = ?,
                        motivo_desactivacion = ?
                    WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$usuarioId, $motivo, $existing['id']]);
        }
    } else {
        // Crear nuevo registro
        $sql = "INSERT INTO empresa_tipos_vehiculo 
                (empresa_id, tipo_vehiculo_codigo, activo, fecha_activacion, activado_por)
                VALUES (?, ?, ?, NOW(), ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$empresaId, $tipoVehiculo, $activo, $usuarioId]);
    }
    
    // Sincronizar con configuracion_precios
    if ($activo) {
        crearConfiguracionPreciosSiNoExiste($db, $empresaId, $tipoVehiculo);
    } else {
        $sqlPrecios = "UPDATE configuracion_precios SET activo = 0 WHERE empresa_id = ? AND tipo_vehiculo = ?";
        $stmtPrecios = $db->prepare($sqlPrecios);
        $stmtPrecios->execute([$empresaId, $tipoVehiculo]);
    }
    
    return [
        'conductores_afectados' => $conductoresAfectados,
        'conductores' => $conductores
    ];
}

/**
 * Toggle usando la tabla legacy (configuracion_precios)
 */
function toggleVehicleLegacy($db, $empresaId, $tipoVehiculo, $activo) {
    $check = "SELECT id FROM configuracion_precios WHERE empresa_id = ? AND tipo_vehiculo = ?";
    $stmtCheck = $db->prepare($check);
    $stmtCheck->execute([$empresaId, $tipoVehiculo]);
    $exists = $stmtCheck->fetch();
    
    if ($activo) {
        if ($exists) {
            $sql = "UPDATE configuracion_precios SET activo = 1, fecha_actualizacion = NOW() WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$exists['id']]);
        } else {
            crearConfiguracionPreciosSiNoExiste($db, $empresaId, $tipoVehiculo);
        }
    } else {
        if ($exists) {
            $sql = "UPDATE configuracion_precios SET activo = 0, fecha_actualizacion = NOW() WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$exists['id']]);
        }
    }
}

/**
 * Obtiene los conductores que serán afectados por la desactivación
 */
function getConductoresAfectados($db, $empresaId, $tipoVehiculo) {
    $query = "SELECT 
                u.id,
                u.nombre,
                u.apellido,
                u.email,
                u.email,
                dc.vehiculo_tipo as tipo_vehiculo
              FROM usuarios u
              INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
              WHERE u.empresa_id = ?
              AND dc.vehiculo_tipo = ?
              AND dc.estado_verificacion = 'aprobado'
              AND u.es_activo = 1";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$empresaId, $tipoVehiculo]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Crea configuración de precios si no existe
 */
function crearConfiguracionPreciosSiNoExiste($db, $empresaId, $tipoVehiculo) {
    // Verificar si existe
    $check = "SELECT id FROM configuracion_precios WHERE empresa_id = ? AND tipo_vehiculo = ?";
    $stmtCheck = $db->prepare($check);
    $stmtCheck->execute([$empresaId, $tipoVehiculo]);
    
    if ($stmtCheck->fetch()) {
        // Ya existe, solo activar
        $sql = "UPDATE configuracion_precios SET activo = 1, fecha_actualizacion = NOW() 
                WHERE empresa_id = ? AND tipo_vehiculo = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$empresaId, $tipoVehiculo]);
        return;
    }
    
    // Buscar configuración global para copiar
    $globalQuery = "SELECT * FROM configuracion_precios 
                    WHERE empresa_id IS NULL AND tipo_vehiculo = ? AND activo = 1 LIMIT 1";
    $globalStmt = $db->prepare($globalQuery);
    $globalStmt->execute([$tipoVehiculo]);
    $global = $globalStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($global) {
        // Copiar de global
        $sql = "INSERT INTO configuracion_precios (
                empresa_id, tipo_vehiculo, tarifa_base, costo_por_km, costo_por_minuto,
                tarifa_minima, tarifa_maxima, recargo_hora_pico, recargo_nocturno, 
                recargo_festivo, descuento_distancia_larga, umbral_km_descuento,
                comision_plataforma, comision_metodo_pago, distancia_minima, 
                distancia_maxima, tiempo_espera_gratis, costo_tiempo_espera, activo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $empresaId, $tipoVehiculo,
            $global['tarifa_base'],
            $global['costo_por_km'],
            $global['costo_por_minuto'],
            $global['tarifa_minima'],
            $global['tarifa_maxima'],
            $global['recargo_hora_pico'],
            $global['recargo_nocturno'],
            $global['recargo_festivo'],
            $global['descuento_distancia_larga'],
            $global['umbral_km_descuento'],
            $global['comision_plataforma'],
            $global['comision_metodo_pago'],
            $global['distancia_minima'],
            $global['distancia_maxima'],
            $global['tiempo_espera_gratis'],
            $global['costo_tiempo_espera']
        ]);
    } else {
        // Crear con valores por defecto
        $sql = "INSERT INTO configuracion_precios (
                empresa_id, tipo_vehiculo, tarifa_base, costo_por_km, costo_por_minuto,
                tarifa_minima, activo
            ) VALUES (?, ?, 5000, 2000, 200, 5000, 1)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$empresaId, $tipoVehiculo]);
    }
}

/**
 * Envía notificaciones a los conductores afectados
 */
function notificarConductoresAfectados($db, $empresaId, $tipoVehiculo, $conductores) {
    if (empty($conductores)) return;
    
    // Obtener nombre de la empresa
    $stmtEmpresa = $db->prepare("SELECT nombre FROM empresas_transporte WHERE id = ?");
    $stmtEmpresa->execute([$empresaId]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);
    $nombreEmpresa = $empresa['nombre'] ?? 'Tu empresa';
    
    // Nombre del tipo de vehículo
    $nombresTipo = [
        'moto' => 'Moto',
        'motocarro' => 'Motocarro',
        'taxi' => 'Taxi',
        'carro' => 'Carro'
    ];
    $nombreTipo = $nombresTipo[$tipoVehiculo] ?? $tipoVehiculo;
    
    // Verificar si existe tabla de notificaciones
    $notifTableExists = checkTableExists($db, 'empresa_vehiculo_notificaciones');
    
    foreach ($conductores as $conductor) {
        if (empty($conductor['email'])) continue;
        
        try {
            $notifId = null;
            $asunto = "Cambio en tipos de vehículo - {$nombreEmpresa}";
            $mensaje = "El tipo de vehículo '{$nombreTipo}' ha sido desactivado por tu empresa.";
            
            // Registrar notificación en BD si existe la tabla
            if ($notifTableExists) {
                $insertNotif = "INSERT INTO empresa_vehiculo_notificaciones 
                                (conductor_id, empresa_id, tipo_vehiculo_codigo, tipo_notificacion, estado, asunto, mensaje)
                                VALUES (?, ?, ?, 'email', 'pendiente', ?, ?)";
                
                $stmtNotif = $db->prepare($insertNotif);
                $stmtNotif->execute([
                    $conductor['id'],
                    $empresaId,
                    $tipoVehiculo,
                    $asunto,
                    $mensaje
                ]);
                $notifId = $db->lastInsertId();
            }
            
            // Enviar email
            $enviado = enviarEmailDesactivacionVehiculo(
                $conductor['email'],
                $conductor['nombre'] . ' ' . ($conductor['apellido'] ?? ''),
                $nombreEmpresa,
                $nombreTipo
            );
            
            // Actualizar estado de notificación si existe la tabla
            if ($notifTableExists && $notifId) {
                $estadoNotif = $enviado ? 'enviada' : 'fallida';
                $updateNotif = "UPDATE empresa_vehiculo_notificaciones 
                               SET estado = ?, enviado_en = NOW(), intentos = intentos + 1
                               WHERE id = ?";
                $stmtUpdate = $db->prepare($updateNotif);
                $stmtUpdate->execute([$estadoNotif, $notifId]);
            }
            
        } catch (Exception $e) {
            error_log("Error notificando conductor {$conductor['id']}: " . $e->getMessage());
        }
    }
}

/**
 * Envía email al conductor sobre desactivación de tipo de vehículo
 */
function enviarEmailDesactivacionVehiculo($email, $nombreConductor, $nombreEmpresa, $tipoVehiculo) {
    try {
        $subject = "⚠️ Cambio en tipos de vehículo - {$nombreEmpresa}";
        
        $bodyContent = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='text-align: center; margin-bottom: 30px;'>
                <img src='https://viax.com.co/images/logo.png' alt='Viax' style='height: 40px;'>
            </div>
            
            <div style='background: linear-gradient(135deg, #FFF3E0 0%, #FFE0B2 100%); border-radius: 16px; padding: 25px; margin-bottom: 20px; border-left: 4px solid #FF9800;'>
                <h2 style='color: #E65100; margin: 0 0 10px 0; font-size: 20px;'>
                    ⚠️ Tipo de vehículo desactivado
                </h2>
                <p style='color: #BF360C; margin: 0; font-size: 14px;'>
                    Tu empresa ha realizado cambios en los tipos de vehículo habilitados.
                </p>
            </div>
            
            <p style='font-size: 16px; color: #333; margin-bottom: 20px;'>
                Hola <strong>{$nombreConductor}</strong>,
            </p>
            
            <p style='font-size: 14px; color: #555; line-height: 1.6; margin-bottom: 20px;'>
                Te informamos que <strong>{$nombreEmpresa}</strong> ha desactivado el tipo de vehículo 
                <strong style='color: #1976D2;'>{$tipoVehiculo}</strong> de su flota.
            </p>
            
            <div style='background: #F5F5F5; border-radius: 12px; padding: 20px; margin-bottom: 20px;'>
                <h3 style='color: #333; margin: 0 0 15px 0; font-size: 16px;'>
                    📋 ¿Qué significa esto?
                </h3>
                <ul style='color: #666; font-size: 14px; line-height: 1.8; padding-left: 20px; margin: 0;'>
                    <li>No podrás recibir nuevas solicitudes de viaje para vehículos tipo <strong>{$tipoVehiculo}</strong></li>
                    <li>Tus viajes en curso no se verán afectados</li>
                    <li>Tu perfil y calificaciones permanecen intactos</li>
                </ul>
            </div>
            
            <div style='background: #E3F2FD; border-radius: 12px; padding: 15px; margin-bottom: 20px;'>
                <p style='color: #1565C0; font-size: 14px; margin: 0;'>
                    💡 <strong>Consejo:</strong> Si tienes dudas sobre esta decisión, te recomendamos 
                    contactar directamente a tu empresa para más información.
                </p>
            </div>
            
            <hr style='border: none; border-top: 1px solid #E0E0E0; margin: 25px 0;'>
            
            <p style='font-size: 12px; color: #999; text-align: center;'>
                Este es un mensaje automático enviado por Viax.<br>
                Si tienes preguntas, contacta a soporte@viax.com.co
            </p>
        </div>";
        
        $altBody = "Hola {$nombreConductor},\n\n" .
                   "Te informamos que {$nombreEmpresa} ha desactivado el tipo de vehículo '{$tipoVehiculo}' de su flota.\n\n" .
                   "¿Qué significa esto?\n" .
                   "- No podrás recibir nuevas solicitudes para vehículos tipo {$tipoVehiculo}\n" .
                   "- Tus viajes en curso no se verán afectados\n" .
                   "- Tu perfil y calificaciones permanecen intactos\n\n" .
                   "Si tienes dudas, contacta a tu empresa.\n\n" .
                   "Saludos,\nEl equipo de Viax";
        
        // Usar PHPMailer
        $vendorPath = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($vendorPath)) {
            error_log("Vendor autoload no encontrado");
            return false;
        }
        require_once $vendorPath;
        
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'viaxoficialcol@gmail.com';
        $mail->Password = 'filz vqel gadn kugb';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom('viaxoficialcol@gmail.com', 'Viax');
        $mail->addAddress($email, $nombreConductor);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $bodyContent;
        $mail->AltBody = $altBody;
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Error enviando email desactivación vehículo: " . $e->getMessage());
        return false;
    }
}
?>
