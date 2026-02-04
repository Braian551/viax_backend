<?php
/**
 * API: Documentos de Conductores para Empresas
 * 
 * Este endpoint permite a las empresas ver y gestionar los documentos
 * de los conductores que han solicitado vincularse o están vinculados a ella.
 * 
 * GET: Listar documentos de conductores de la empresa
 * POST: Aprobar/rechazar documentos de un conductor
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        handleGet($db);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handlePost($db);
    } else {
        throw new Exception('Método no permitido');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * GET: Listar documentos de conductores de la empresa
 */
function handleGet($db) {
    // Obtener parámetros
    $empresa_id = isset($_GET['empresa_id']) ? intval($_GET['empresa_id']) : 0;
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $conductor_id = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : null;
    $estado_verificacion = isset($_GET['estado_verificacion']) ? $_GET['estado_verificacion'] : null;
    $incluir_solicitudes = isset($_GET['incluir_solicitudes']) ? $_GET['incluir_solicitudes'] === 'true' : true;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;

    // Validar empresa_id
    if ($empresa_id <= 0) {
        throw new Exception('ID de empresa inválido');
    }

    // Verificar que el usuario pertenece a la empresa o es admin de ella
    if ($user_id > 0) {
        $stmt = $db->prepare("SELECT id, tipo_usuario, empresa_id FROM usuarios WHERE id = :id");
        $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception('Usuario no encontrado');
        }
        
        // Permitir si es tipo 'empresa' y su empresa_id coincide
        // O si es admin de la empresa
        $isAuthorized = ($user['tipo_usuario'] === 'empresa' && $user['empresa_id'] == $empresa_id) ||
                        ($user['tipo_usuario'] === 'administrador');
        
        if (!$isAuthorized) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'No tienes permisos para ver documentos de esta empresa'
            ]);
            exit;
        }
    }

    // Query para estadísticas de la empresa
    // Incluye tanto conductores como clientes que tienen solicitud de vinculación pendiente
    $stats_sql = "SELECT 
                    COUNT(DISTINCT u.id) as total,
                    SUM(CASE WHEN (dc.estado_verificacion IN ('pendiente', 'en_revision') OR dc.estado_verificacion IS NULL) AND (sv.estado IS NULL OR sv.estado != 'rechazada') THEN 1 ELSE 0 END) as pendientes,
                    SUM(CASE WHEN dc.estado_verificacion = 'en_revision' AND (sv.estado IS NULL OR sv.estado != 'rechazada') THEN 1 ELSE 0 END) as en_revision,
                    SUM(CASE WHEN dc.estado_verificacion = 'aprobado' AND (sv.estado IS NULL OR sv.estado != 'rechazada') THEN 1 ELSE 0 END) as aprobados,
                    SUM(CASE WHEN dc.estado_verificacion = 'rechazado' OR sv.estado = 'rechazada' THEN 1 ELSE 0 END) as rechazados,
                    SUM(CASE WHEN (
                        (dc.licencia_vencimiento IS NOT NULL AND dc.licencia_vencimiento < CURRENT_DATE) OR
                        (dc.soat_vencimiento IS NOT NULL AND dc.soat_vencimiento < CURRENT_DATE) OR
                        (dc.tecnomecanica_vencimiento IS NOT NULL AND dc.tecnomecanica_vencimiento < CURRENT_DATE)
                    ) THEN 1 ELSE 0 END) as con_documentos_vencidos
                  FROM usuarios u 
                  LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
            LEFT JOIN (
                SELECT DISTINCT ON (conductor_id) *
                FROM solicitudes_vinculacion_conductor
                WHERE empresa_id = :empresa_filter_stats
                ORDER BY conductor_id, 
                    CASE WHEN estado = 'pendiente' THEN 1 WHEN estado = 'rechazada' THEN 2 ELSE 3 END ASC,
                    creado_en DESC
            ) sv ON u.id = sv.conductor_id
                  WHERE (u.tipo_usuario = 'conductor' OR u.id IN (SELECT conductor_id FROM solicitudes_vinculacion_conductor WHERE empresa_id = :empresa_id_stats))
                  AND (u.empresa_id = :empresa_id 
                       OR u.id IN (SELECT conductor_id FROM solicitudes_vinculacion_conductor WHERE empresa_id = :empresa_id2))";
    
    $stmt = $db->prepare($stats_sql);
    $stmt->bindParam(':empresa_filter_stats', $empresa_id, PDO::PARAM_INT);
    $stmt->bindParam(':empresa_id_stats', $empresa_id, PDO::PARAM_INT);
    $stmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $stmt->bindParam(':empresa_id2', $empresa_id, PDO::PARAM_INT);
    $stmt->execute();
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);

    // Contar solicitudes pendientes de vinculación
    $solicitudesQuery = "SELECT COUNT(*) FROM solicitudes_vinculacion_conductor WHERE empresa_id = :empresa_id AND estado = 'pendiente'";
    $solicitudesStmt = $db->prepare($solicitudesQuery);
    $solicitudesStmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $solicitudesStmt->execute();
    $solicitudesPendientes = $solicitudesStmt->fetchColumn();

    $estadisticas = [
        'total_conductores' => intval($stats_result['total']),
        'pendientes_verificacion' => intval($stats_result['pendientes']),
        'en_revision' => intval($stats_result['en_revision']),
        'aprobados' => intval($stats_result['aprobados']),
        'rechazados' => intval($stats_result['rechazados']),
        'con_documentos_vencidos' => intval($stats_result['con_documentos_vencidos']),
        'solicitudes_pendientes' => intval($solicitudesPendientes),
    ];

    // Construir query principal
    // Acepta conductores vinculados O cualquier usuario (cliente/conductor) con solicitud pendiente
    $where_clauses = [];
    
    // Filtrar por empresa: conductores vinculados O usuarios con solicitud pendiente
    if ($incluir_solicitudes) {
        $where_clauses[] = "((u.tipo_usuario = 'conductor' AND u.empresa_id = :empresa_id) OR u.id IN (SELECT conductor_id FROM solicitudes_vinculacion_conductor WHERE empresa_id = :empresa_id2))";
    } else {
        $where_clauses[] = "u.tipo_usuario = 'conductor' AND u.empresa_id = :empresa_id";
    }
    
    $params = [':empresa_id' => $empresa_id];
    if ($incluir_solicitudes) {
        $params[':empresa_id2'] = $empresa_id;
    }

    if ($conductor_id !== null) {
        $where_clauses[] = "u.id = :conductor_id";
        $params[':conductor_id'] = $conductor_id;
    }

    $search = isset($_GET['search']) ? trim($_GET['search']) : null;
    if ($search) {
        $where_clauses[] = "(u.nombre ILIKE :search OR u.apellido ILIKE :search OR u.email ILIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($estado_verificacion !== null) {
        if ($estado_verificacion === 'rechazado') {
            $where_clauses[] = "(dc.estado_verificacion = 'rechazado' OR u.id IN (SELECT conductor_id FROM solicitudes_vinculacion_conductor WHERE empresa_id = :empresa_id_rech AND estado = 'rechazada'))";
            $params[':empresa_id_rech'] = $empresa_id;
        } else {
            // Excluir solicitudes rechazadas de otros filtros
            $where_clauses[] = "u.id NOT IN (SELECT conductor_id FROM solicitudes_vinculacion_conductor WHERE empresa_id = :empresa_id_norech AND estado = 'rechazada')";
            $params[':empresa_id_norech'] = $empresa_id;

            if ($estado_verificacion === 'pendiente') {
                $where_clauses[] = "(dc.estado_verificacion IN ('pendiente', 'en_revision') OR dc.estado_verificacion IS NULL)";
            } else {
                $where_clauses[] = "dc.estado_verificacion = :estado";
                $params[':estado'] = $estado_verificacion;
            }
        }
    }

    $where_sql = implode(' AND ', $where_clauses);
    $offset = ($page - 1) * $per_page;

    // Query para contar total
    $count_sql = "SELECT COUNT(DISTINCT u.id) as total 
                  FROM usuarios u
                  LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
                  WHERE $where_sql";

    $stmt = $db->prepare($count_sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $total_conductores = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Query principal
    $sql = "SELECT 
                dc.*,
                u.id as usuario_id,
                u.nombre,
                u.apellido,
                u.email,
                u.telefono,
                u.foto_perfil,
                u.es_verificado,
                u.es_activo,
                u.empresa_id,
                u.fecha_registro as usuario_creado_en,
                sv.id as solicitud_id,
                sv.estado as estado_solicitud,
                sv.mensaje_conductor,
                sv.creado_en as fecha_solicitud
            FROM usuarios u
            LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
            LEFT JOIN (
                SELECT DISTINCT ON (conductor_id) *
                FROM solicitudes_vinculacion_conductor
                WHERE empresa_id = :empresa_filter
                ORDER BY conductor_id, 
                    CASE WHEN estado = 'pendiente' THEN 1 WHEN estado = 'rechazada' THEN 2 ELSE 3 END ASC,
                    creado_en DESC
            ) sv ON u.id = sv.conductor_id
            WHERE $where_sql
            ORDER BY 
                CASE WHEN sv.id IS NOT NULL THEN 0 ELSE 1 END ASC,
                CASE 
                     WHEN dc.estado_verificacion = 'en_revision' THEN 0 
                     WHEN dc.estado_verificacion = 'pendiente' THEN 1
                     WHEN dc.estado_verificacion IS NULL THEN 2
                     ELSE 3 
                END ASC,
                u.fecha_registro DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':empresa_filter', $empresa_id, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $conductores = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Calcular documentos
        $documentos_requeridos = [
            'licencia_conduccion' => 'Número de licencia',
            'licencia_vencimiento' => 'Vencimiento de licencia',
            'vehiculo_placa' => 'Placa del vehículo',
            'soat_numero' => 'Número SOAT',
            'soat_vencimiento' => 'Vencimiento SOAT',
            'tecnomecanica_numero' => 'Número Tecnomecánica',
            'tecnomecanica_vencimiento' => 'Vencimiento Tecnomecánica',
        ];

        $pendientes = [];
        $completos = 0;
        foreach ($documentos_requeridos as $campo => $nombre) {
            if (empty($row[$campo])) {
                $pendientes[] = $nombre;
            } else {
                $completos++;
            }
        }

        // Verificar documentos vencidos
        $documentos_vencidos = [];
        $hoy = date('Y-m-d');
        
        if (!empty($row['licencia_vencimiento']) && $row['licencia_vencimiento'] < $hoy) {
            $documentos_vencidos[] = 'Licencia de conducción';
        }
        if (!empty($row['soat_vencimiento']) && $row['soat_vencimiento'] < $hoy) {
            $documentos_vencidos[] = 'SOAT';
        }
        if (!empty($row['tecnomecanica_vencimiento']) && $row['tecnomecanica_vencimiento'] < $hoy) {
            $documentos_vencidos[] = 'Tecnomecánica';
        }

        // Determinar si es solicitud pendiente o conductor vinculado
        // Only mark as pending if the state is actually 'pendiente'
        $esSolicitudPendiente = !empty($row['solicitud_id']) && $row['estado_solicitud'] === 'pendiente';
        $estaVinculado = $row['empresa_id'] == $empresa_id;

        $conductores[] = [
            'id' => $row['id'] ?? null,
            'usuario_id' => intval($row['usuario_id']),
            
            // Estado de vinculación
            'es_solicitud_pendiente' => $esSolicitudPendiente,
            'esta_vinculado' => $estaVinculado,
            'solicitud_id' => $row['solicitud_id'],
            'estado_solicitud' => $row['estado_solicitud'],
            'mensaje_conductor' => $row['mensaje_conductor'],
            'fecha_solicitud' => $row['fecha_solicitud'],
            
            // Información del usuario
            'nombre_completo' => trim(($row['nombre'] ?? '') . ' ' . ($row['apellido'] ?? '')),
            'nombre' => $row['nombre'],
            'apellido' => $row['apellido'],
            'email' => $row['email'],
            'telefono' => $row['telefono'],
            'foto_perfil' => $row['foto_perfil'],
            'es_verificado' => $row['es_verificado'],
            'es_activo' => $row['es_activo'],
            'usuario_creado_en' => $row['usuario_creado_en'],
            
            // Licencia de conducción
            'licencia_conduccion' => $row['licencia_conduccion'],
            'licencia_vencimiento' => $row['licencia_vencimiento'],
            'licencia_categoria' => $row['licencia_categoria'],
            'licencia_foto_url' => $row['licencia_foto_url'],
            'licencia_tipo_archivo' => $row['licencia_tipo_archivo'] ?? 'imagen',
            
            // Vehículo
            'vehiculo_tipo' => $row['vehiculo_tipo'],
            'vehiculo_marca' => $row['vehiculo_marca'],
            'vehiculo_modelo' => $row['vehiculo_modelo'],
            'vehiculo_anio' => $row['vehiculo_anio'],
            'vehiculo_color' => $row['vehiculo_color'],
            'vehiculo_placa' => $row['vehiculo_placa'],
            'vehiculo_foto_url' => $row['foto_vehiculo'],
            
            // Documentos
            'soat_numero' => $row['soat_numero'],
            'soat_vencimiento' => $row['soat_vencimiento'],
            'soat_foto_url' => $row['soat_foto_url'],
            'soat_tipo_archivo' => $row['soat_tipo_archivo'] ?? 'imagen',
            'tecnomecanica_numero' => $row['tecnomecanica_numero'],
            'tecnomecanica_vencimiento' => $row['tecnomecanica_vencimiento'],
            'tecnomecanica_foto_url' => $row['tecnomecanica_foto_url'],
            'tecnomecanica_tipo_archivo' => $row['tecnomecanica_tipo_archivo'] ?? 'imagen',
            'tarjeta_propiedad_numero' => $row['tarjeta_propiedad_numero'],
            'tarjeta_propiedad_foto_url' => $row['tarjeta_propiedad_foto_url'],
            'tarjeta_propiedad_tipo_archivo' => $row['tarjeta_propiedad_tipo_archivo'] ?? 'imagen',
            
            // Estado de verificación
            'estado_verificacion' => $row['estado_verificacion'] ?? 'pendiente',
            'fecha_ultima_verificacion' => $row['fecha_ultima_verificacion'],
            
            // Análisis de documentos
            'documentos_pendientes' => $pendientes,
            'documentos_completos' => $completos,
            'total_documentos_requeridos' => count($documentos_requeridos),
            'porcentaje_completitud' => round(($completos / count($documentos_requeridos)) * 100, 2),
            'documentos_vencidos' => $documentos_vencidos,
            'tiene_documentos_vencidos' => count($documentos_vencidos) > 0,
        ];
    }

    echo json_encode([
        'success' => true,
        'message' => 'Documentos de conductores obtenidos exitosamente',
        'data' => [
            'conductores' => $conductores,
            'estadisticas' => $estadisticas,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => intval($total_conductores),
                'total_pages' => ceil($total_conductores / $per_page),
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * POST: Aprobar o rechazar documentos/solicitud de un conductor
 */
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Datos inválidos');
    }
    
    $empresa_id = isset($input['empresa_id']) ? intval($input['empresa_id']) : 0;
    $conductor_id = isset($input['conductor_id']) ? intval($input['conductor_id']) : 0;
    $accion = isset($input['accion']) ? $input['accion'] : '';
    $procesado_por = isset($input['procesado_por']) ? intval($input['procesado_por']) : 0;
    $razon = isset($input['razon']) ? trim($input['razon']) : '';
    
    if ($empresa_id <= 0 || $conductor_id <= 0) {
        throw new Exception('IDs inválidos');
    }
    
    if (!in_array($accion, ['aprobar', 'rechazar', 'aprobar_solicitud', 'rechazar_solicitud', 'aprobar_documentos', 'rechazar_documentos', 'desactivar', 'desvincular'])) {
        throw new Exception('Acción inválida');
    }
    
    // Map simple actions to specific ones
    if ($accion === 'aprobar') {
        $accion = 'aprobar_documentos';
    } elseif ($accion === 'rechazar') {
        $accion = 'rechazar_documentos';
    }
    
    $db->beginTransaction();
    
    try {
        if ($accion === 'aprobar_solicitud') {
            // Buscar la solicitud pendiente
            $solicitudQuery = "SELECT id FROM solicitudes_vinculacion_conductor 
                              WHERE conductor_id = :conductor_id AND empresa_id = :empresa_id AND estado = 'pendiente'";
            $solicitudStmt = $db->prepare($solicitudQuery);
            $solicitudStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
            $solicitudStmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
            $solicitudStmt->execute();
            $solicitudId = $solicitudStmt->fetchColumn();
            
            if (!$solicitudId) {
                // Debugging: Check if a request exists in ANY state
                $debugQuery = "SELECT estado FROM solicitudes_vinculacion_conductor 
                               WHERE conductor_id = :conductor_id AND empresa_id = :empresa_id";
                $debugStmt = $db->prepare($debugQuery);
                $debugStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
                $debugStmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
                $debugStmt->execute();
                $estadoActual = $debugStmt->fetchColumn();

                if ($estadoActual) {
                    throw new Exception("La solicitud no está pendiente. Estado actual: $estadoActual");
                } else {
                    throw new Exception("No existe ninguna solicitud para este conductor (ID: $conductor_id, Empresa: $empresa_id)");
                }
            }
            
            // 1. Aprobar la solicitud de vinculación
            // Usar función SQL para aprobar
            $query = "SELECT aprobar_vinculacion_conductor(:solicitud_id, :procesado_por) as resultado";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':solicitud_id', $solicitudId, PDO::PARAM_INT);
            $stmt->bindParam(':procesado_por', $procesado_por, PDO::PARAM_INT);
            $stmt->execute();
            
            $resultado = json_decode($stmt->fetchColumn(), true);
            
            if (!$resultado['success']) {
                throw new Exception($resultado['message']);
            }

            // 2. ACTUALIZAR ESTADO DE VERIFICACIÓN DEL CONDUCTOR (CRITICAL FIX)
            // Si la empresa aprueba la vinculación, asumimos que verificó manual o visualmente al conductor
            $checkQuery = "SELECT COUNT(*) FROM detalles_conductor WHERE usuario_id = :conductor_id";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([':conductor_id' => $conductor_id]);
            
            if ($checkStmt->fetchColumn() > 0) {
                 $updateCond = "UPDATE detalles_conductor 
                               SET estado_verificacion = 'aprobado', 
                                   estado_aprobacion = 'aprobado',
                                   aprobado = 1,
                                   fecha_ultima_verificacion = NOW(),
                                   actualizado_en = NOW()
                               WHERE usuario_id = :conductor_id";
                 $db->prepare($updateCond)->execute([':conductor_id' => $conductor_id]);
            } else {
                 // Si no existe, crearlo como aprobado
                 $insertCond = "INSERT INTO detalles_conductor (
                                    usuario_id, estado_verificacion, estado_aprobacion, aprobado, 
                                    fecha_ultima_verificacion, creado_en, actualizado_en
                                ) VALUES (
                                    :conductor_id, 'aprobado', 'aprobado', 1,
                                    NOW(), NOW(), NOW()
                                )";
                 $db->prepare($insertCond)->execute([':conductor_id' => $conductor_id]);
            }

            // 3. ACTUALIZAR USUARIO A VERIFICADO, ACTIVO Y TIPO CONDUCTOR
            $updateUser = "UPDATE usuarios SET es_verificado = 1, es_activo = 1, tipo_usuario = 'conductor' WHERE id = :conductor_id";
            $db->prepare($updateUser)->execute([':conductor_id' => $conductor_id]);
            
            // 4. PREPARAR DATOS PARA EMAIL (CRITICAL FIX: Fetch real data)
            try {
                // Obtener datos del conductor y sus documentos
                $stmt = $db->prepare("
                    SELECT u.email, u.nombre, u.apellido, dc.licencia_conduccion, dc.vehiculo_placa 
                    FROM usuarios u
                    LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
                    WHERE u.id = :id
                ");
                $stmt->execute([':id' => $conductor_id]);
                $conductorData = $stmt->fetch(PDO::FETCH_ASSOC);

                // Obtener datos de la empresa para el logo/nombre
                $stmtEmp = $db->prepare("SELECT nombre AS nombre_empresa, logo_url FROM empresas_transporte WHERE id = :id");
                $stmtEmp->execute([':id' => $empresa_id]);
                $empresaData = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($conductorData && $empresaData) {
                    $nombreCompleto = trim($conductorData['nombre'] . ' ' . $conductorData['apellido']);
                    $documentos = [
                        'licencia' => $conductorData['licencia_conduccion'] ?: 'Verificada',
                        'placa' => $conductorData['vehiculo_placa'] ?: 'Verificada'
                    ];

                    require_once __DIR__ . '/../utils/Mailer.php';
                    
                    // Asegurarnos de usar una función que acepte detalles de empresa si Mailer la tiene, 
                    // o inyectarlos en el cuerpo. Por ahora usamos la standard pero con datos reales.
                    // TODO: Si sendConductorApprovedEmail no soporta logo de empresa, deberíamos actualizar Mailer.php también.
                    // Asumiremos que Mailer.php necesita update o ya lo tiene.
                    Mailer::sendConductorApprovedEmail(
                        $conductorData['email'], 
                        $nombreCompleto, 
                        $documentos,
                        $empresaData // Passing company data array
                    );
                }
            } catch (Exception $mailError) {
                // Log error but don't fail transaction
                error_log("Error enviando email conductor: " . $mailError->getMessage());
            }

            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Conductor aprobado y vinculado exitosamente'
            ]);
            
        } elseif ($accion === 'rechazar_solicitud') {
            // Buscar y rechazar solicitud
            $solicitudQuery = "SELECT id FROM solicitudes_vinculacion_conductor 
                              WHERE conductor_id = :conductor_id AND empresa_id = :empresa_id AND estado = 'pendiente'";
            $solicitudStmt = $db->prepare($solicitudQuery);
            $solicitudStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
            $solicitudStmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
            $solicitudStmt->execute();
            $solicitudId = $solicitudStmt->fetchColumn();
            
            if (!$solicitudId) {
                throw new Exception('No hay solicitud pendiente para este conductor');
            }
            
            $query = "SELECT rechazar_vinculacion_conductor(:solicitud_id, :procesado_por, :razon) as resultado";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':solicitud_id', $solicitudId, PDO::PARAM_INT);
            $stmt->bindParam(':procesado_por', $procesado_por, PDO::PARAM_INT);
            $stmt->bindParam(':razon', $razon, PDO::PARAM_STR);
            $stmt->execute();
            
            // ACTUALIZAR ESTADO DEL CONDUCTOR EN detalles_conductor
            $updateCond = "UPDATE detalles_conductor 
                           SET estado_verificacion = 'rechazado',
                               estado_aprobacion = 'rechazado',
                               aprobado = 0,
                               razon_rechazo = :razon,
                               fecha_ultima_verificacion = NOW(),
                               actualizado_en = NOW()
                           WHERE usuario_id = :conductor_id";
            $updateStmt = $db->prepare($updateCond);
            $updateStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
            $updateStmt->bindParam(':razon', $razon, PDO::PARAM_STR);
            $updateStmt->execute();
            
            // Enviar correo de rechazo de vinculación
            try {
                $stmt = $db->prepare("SELECT email, nombre, apellido FROM usuarios WHERE id = :id");
                $stmt->bindParam(':id', $conductor_id);
                $stmt->execute();
                $conductorData = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($conductorData) {
                    $nombreCompleto = trim($conductorData['nombre'] . ' ' . $conductorData['apellido']);
                    require_once __DIR__ . '/../utils/Mailer.php';
                    Mailer::sendConductorRejectedEmail($conductorData['email'], $nombreCompleto, [], $razon);
                }
            } catch (Exception $mailError) {}
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Solicitud rechazada'
            ]);
            
        } elseif ($accion === 'aprobar_documentos') {
            // Aprobar documentos de conductor ya vinculado
            // First check if detalles_conductor exists for this user
            $checkQuery = "SELECT COUNT(*) FROM detalles_conductor WHERE usuario_id = :conductor_id";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
            $checkStmt->execute();
            $exists = $checkStmt->fetchColumn() > 0;
            
            if (!$exists) {
                // Create detalles_conductor record
                $insertQuery = "INSERT INTO detalles_conductor (
                                    usuario_id, 
                                    estado_verificacion,
                                    estado_aprobacion,
                                    aprobado,
                                    fecha_ultima_verificacion, 
                                    creado_en, 
                                    actualizado_en
                                ) 
                               VALUES (
                                    :conductor_id, 
                                    'aprobado',
                                    'aprobado',
                                    1,
                                    NOW(), 
                                    NOW(), 
                                    NOW()
                                )";
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
                $insertStmt->execute();
            } else {
                // Update existing record with all approval flags
                $updateQuery = "UPDATE detalles_conductor 
                               SET estado_verificacion = 'aprobado',
                                   estado_aprobacion = 'aprobado',
                                   aprobado = 1,
                                   fecha_ultima_verificacion = NOW(),
                                   actualizado_en = NOW()
                               WHERE usuario_id = :conductor_id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
                $updateStmt->execute();
            }
            
            // Activar conductor, vincular a la empresa, y asegurar tipo_usuario correcto
            $activarQuery = "UPDATE usuarios SET es_activo = 1, es_verificado = 1, empresa_id = :empresa_id, tipo_usuario = 'conductor' WHERE id = :id";
            $activarStmt = $db->prepare($activarQuery);
            $activarStmt->bindParam(':id', $conductor_id, PDO::PARAM_INT);
            $activarStmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
            $activarStmt->execute();

            // También aprobar cualquier solicitud pendiente para mantener consistencia
            $approveReqQuery = "UPDATE solicitudes_vinculacion_conductor 
                               SET estado = 'aprobada', actualizado_en = NOW() 
                               WHERE conductor_id = :conductor_id AND empresa_id = :empresa_id AND estado = 'pendiente'";
            $approveReqStmt = $db->prepare($approveReqQuery);
            $approveReqStmt->execute([':conductor_id' => $conductor_id, ':empresa_id' => $empresa_id]);
            
            // Enviar correo de aprobación con branding de empresa
            try {
                // Fetch conductor data with correct fields
                $stmt = $db->prepare("
                    SELECT u.email, u.nombre, u.apellido, dc.licencia_conduccion, dc.vehiculo_placa
                    FROM usuarios u
                    LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
                    WHERE u.id = :id
                ");
                $stmt->execute([':id' => $conductor_id]);
                $conductorData = $stmt->fetch(PDO::FETCH_ASSOC);

                // Fetch company data for branding
                $stmtEmp = $db->prepare("SELECT nombre AS nombre_empresa, logo_url FROM empresas_transporte WHERE id = :id");
                $stmtEmp->execute([':id' => $empresa_id]);
                $empresaData = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($conductorData) {
                    $nombreCompleto = trim($conductorData['nombre'] . ' ' . $conductorData['apellido']);
                    require_once __DIR__ . '/../utils/Mailer.php';
                    
                    $mailData = [
                        'licencia' => $conductorData['licencia_conduccion'] ?: 'Verificada',
                        'placa' => $conductorData['vehiculo_placa'] ?: 'Verificada'
                    ];
                    
                    Mailer::sendConductorApprovedEmail($conductorData['email'], $nombreCompleto, $mailData, $empresaData);
                }
            } catch (Exception $mailError) {
                error_log("Error enviando email de aprobación: " . $mailError->getMessage());
            }

            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Documentos aprobados. Conductor activado.'
            ]);
            
        } elseif ($accion === 'rechazar_documentos') {
            // Rechazar documentos
            // First check if detalles_conductor exists for this user
            $checkQuery = "SELECT COUNT(*) FROM detalles_conductor WHERE usuario_id = :conductor_id";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
            $checkStmt->execute();
            $exists = $checkStmt->fetchColumn() > 0;
            
            if (!$exists) {
                // Create detalles_conductor record with rejected status
                $insertQuery = "INSERT INTO detalles_conductor (
                                    usuario_id, 
                                    estado_verificacion,
                                    estado_aprobacion,
                                    aprobado, 
                                    razon_rechazo, 
                                    fecha_ultima_verificacion, 
                                    creado_en, 
                                    actualizado_en,
                                    licencia_conduccion,
                                    licencia_vencimiento,
                                    vehiculo_tipo,
                                    vehiculo_placa
                                ) 
                               VALUES (
                                    :conductor_id, 
                                    'rechazado',
                                    'rechazado',
                                    0, 
                                    :razon, 
                                    NOW(), 
                                    NOW(), 
                                    NOW(),
                                    'PENDIENTE',
                                    '2030-01-01',
                                    'auto',
                                    'PENDIENTE'
                                )";
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
                $insertStmt->bindParam(':razon', $razon, PDO::PARAM_STR);
                $insertStmt->execute();
            } else {
                // Update existing record
                $updateQuery = "UPDATE detalles_conductor 
                               SET estado_verificacion = 'rechazado',
                                   estado_aprobacion = 'rechazado',
                                   aprobado = 0,
                                   fecha_ultima_verificacion = NOW(),
                                   razon_rechazo = :razon,
                                   actualizado_en = NOW()
                               WHERE usuario_id = :conductor_id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
                $updateStmt->bindParam(':razon', $razon, PDO::PARAM_STR);
                $updateStmt->execute();
            }
            
            
            // Enviar correo de rechazo
            try {
                $stmt = $db->prepare("SELECT email, nombre, apellido FROM usuarios WHERE id = :id");
                $stmt->bindParam(':id', $conductor_id);
                $stmt->execute();
                $conductorData = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($conductorData) {
                    $nombreCompleto = trim($conductorData['nombre'] . ' ' . $conductorData['apellido']);
                    require_once __DIR__ . '/../utils/Mailer.php';
                    Mailer::sendConductorRejectedEmail($conductorData['email'], $nombreCompleto, [], $razon);
                }
            } catch (Exception $mailError) {
                // Log error but don't fail transaction
                error_log("Error enviando email de rechazo: " . $mailError->getMessage());
            }

            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Documentos rechazados'
            ]);
        } elseif ($accion === 'desactivar') {
            // Desactivar conductor
            $updateQuery = "UPDATE usuarios SET es_activo = 0 WHERE id = :conductor_id AND empresa_id = :empresa_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
            $updateStmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
            $updateStmt->execute();
            
            if ($updateStmt->rowCount() === 0) {
                 throw new Exception("No se pudo desactivar. Verifica que el conductor pertenezca a tu empresa.");
            }

            // Enviar correo de desactivación
            try {
                $stmt = $db->prepare("SELECT email, nombre, apellido FROM usuarios WHERE id = :id");
                $stmt->bindParam(':id', $conductor_id);
                $stmt->execute();
                $conductorData = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($conductorData) {
                    $nombreCompleto = trim($conductorData['nombre'] . ' ' . $conductorData['apellido']);
                    require_once __DIR__ . '/../utils/Mailer.php';
                    Mailer::sendCompanyStatusChangeEmail($conductorData['email'], $nombreCompleto, 'Desactivado');
                }
            } catch (Exception $mailError) {}

            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Conductor desactivado exitosamente'
            ]);

        } elseif ($accion === 'desvincular') {
            // Desvincular conductor (quitar empresa_id)
            $updateQuery = "UPDATE usuarios SET empresa_id = NULL WHERE id = :conductor_id AND empresa_id = :empresa_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
            $updateStmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
            $updateStmt->execute();

             if ($updateStmt->rowCount() === 0) {
                 throw new Exception("No se pudo desvincular. Verifica que el conductor pertenezca a tu empresa.");
            }

            // Enviar correo de desvinculación
             try {
                $stmt = $db->prepare("SELECT email, nombre, apellido FROM usuarios WHERE id = :id");
                $stmt->bindParam(':id', $conductor_id);
                $stmt->execute();
                $conductorData = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($conductorData) {
                    $nombreCompleto = trim($conductorData['nombre'] . ' ' . $conductorData['apellido']);
                    require_once __DIR__ . '/../utils/Mailer.php';
                    Mailer::sendCompanyStatusChangeEmail($conductorData['email'], $nombreCompleto, 'Desvinculado');
                }
            } catch (Exception $mailError) {}

            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Conductor desvinculado exitosamente'
            ]);
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}
?>
