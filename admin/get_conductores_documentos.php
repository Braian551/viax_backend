<?php
// Suprimir warnings y notices en producción
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    // Usar PDO como el resto del backend
    $database = new Database();
    $db = $database->getConnection();

    // Validar método
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Método no permitido');
    }

    // Obtener parámetros
    $admin_id = isset($_GET['admin_id']) ? intval($_GET['admin_id']) : 0;
    $conductor_id = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : null;
    $estado_verificacion = isset($_GET['estado_verificacion']) ? $_GET['estado_verificacion'] : null;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;

    // Validar admin_id
    if ($admin_id <= 0) {
        throw new Exception('ID de administrador inválido');
    }

    // Verificar que es admin
    $stmt = $db->prepare("SELECT tipo_usuario FROM usuarios WHERE id = :id AND tipo_usuario = 'administrador'");
    $stmt->bindParam(':id', $admin_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Acceso denegado. Solo administradores pueden ver documentos.'
        ]);
        exit;
    }

    // Query para contar estadísticas globales (sin filtros de página/estado)
    $stats_sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN dc.estado_verificacion IN ('pendiente', 'en_revision') OR dc.estado_verificacion IS NULL THEN 1 ELSE 0 END) as pendientes,
                    SUM(CASE WHEN dc.estado_verificacion = 'en_revision' THEN 1 ELSE 0 END) as en_revision,
                    SUM(CASE WHEN dc.estado_verificacion = 'aprobado' THEN 1 ELSE 0 END) as aprobados,
                    SUM(CASE WHEN dc.estado_verificacion = 'rechazado' THEN 1 ELSE 0 END) as rechazados,
                    SUM(CASE WHEN (
                        (dc.licencia_vencimiento IS NOT NULL AND dc.licencia_vencimiento < CURRENT_DATE) OR
                        (dc.soat_vencimiento IS NOT NULL AND dc.soat_vencimiento < CURRENT_DATE) OR
                        (dc.tecnomecanica_vencimiento IS NOT NULL AND dc.tecnomecanica_vencimiento < CURRENT_DATE) OR
                        (dc.vencimiento_seguro IS NOT NULL AND dc.vencimiento_seguro < CURRENT_DATE)
                    ) THEN 1 ELSE 0 END) as con_documentos_vencidos
                  FROM usuarios u 
                  LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id 
                  -- Incluir si es conductor OR si tiene detalles (solicitud en proceso aunque sea cliente)
                  WHERE u.tipo_usuario = 'conductor' OR dc.id IS NOT NULL";
    
    $stmt = $db->prepare($stats_sql);
    $stmt->execute();
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);

    $estadisticas = [
        'total_conductores' => intval($stats_result['total']),
        'pendientes_verificacion' => intval($stats_result['pendientes']),
        'en_revision' => intval($stats_result['en_revision']),
        'aprobados' => intval($stats_result['aprobados']),
        'rechazados' => intval($stats_result['rechazados']),
        'con_documentos_vencidos' => intval($stats_result['con_documentos_vencidos']),
    ];

    // Construir query principal con LEFT JOIN para incluir conductores sin detalles
    // Lo mismo aqui: validamos conductor O que tenga detalles
    $where_clauses = ["(u.tipo_usuario = 'conductor' OR dc.id IS NOT NULL)"];
    $params = [];

    if ($conductor_id !== null) {
        $where_clauses[] = "u.id = :conductor_id";
        $params[':conductor_id'] = $conductor_id;
    }

    if ($estado_verificacion !== null) {
        if ($estado_verificacion === 'pendiente') {
            // "Pendientes" en el frontend ahora agrupa:
            // 1. NULL (Sin registro en detalles)
            // 2. 'pendiente' (Registrado pero sin subir)
            // 3. 'en_revision' (Subió documentos y espera aprobación)
            $where_clauses[] = "(dc.estado_verificacion IN ('pendiente', 'en_revision') OR dc.estado_verificacion IS NULL)";
        } else {
            $where_clauses[] = "dc.estado_verificacion = :estado";
            $params[':estado'] = $estado_verificacion;
        }
    }

    $where_sql = implode(' AND ', $where_clauses);
    $offset = ($page - 1) * $per_page;

    // Query para contar total filtrado (para paginación)
    $count_sql = "SELECT COUNT(*) as total 
                  FROM usuarios u
                  LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
                  WHERE $where_sql";

    $stmt = $db->prepare($count_sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $total_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_conductores = $total_result['total'];

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
                u.fecha_registro as usuario_creado_en
            FROM usuarios u
            LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
            WHERE $where_sql
            ORDER BY 
                CASE 
                     WHEN dc.estado_verificacion = 'en_revision' THEN 0 
                     WHEN dc.estado_verificacion = 'pendiente' THEN 1
                     WHEN dc.estado_verificacion IS NULL THEN 2
                     ELSE 3 
                END ASC,
                dc.fecha_ultima_verificacion ASC,
                u.fecha_registro DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $conductores = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Calcular documentos pendientes y verificados
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
            if (empty($row[$campo]) || $row[$campo] === null) {
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
        if (!empty($row['vencimiento_seguro']) && $row['vencimiento_seguro'] < $hoy) {
            $documentos_vencidos[] = 'Seguro';
        }



        $conductores[] = [
            'id' => $row['id'] ?? null,
            'usuario_id' => intval($row['usuario_id']),
            
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
            'licencia_expedicion' => $row['licencia_expedicion'],
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
            'vehiculo_tipo_archivo' => 'imagen', // Default for uploaded photos
            
            // Seguros y documentos
            'aseguradora' => $row['aseguradora'],
            'numero_poliza_seguro' => $row['numero_poliza_seguro'],
            'vencimiento_seguro' => $row['vencimiento_seguro'],
            'seguro_foto_url' => $row['seguro_foto_url'],
            'seguro_tipo_archivo' => $row['seguro_tipo_archivo'] ?? 'imagen',
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
            
            // Estado de aprobación
            'aprobado' => $row['aprobado'] ?? 0,
            'estado_aprobacion' => $row['estado_aprobacion'] ?? 'pendiente',
            'estado_verificacion' => $row['estado_verificacion'] ?? 'pendiente',
            'fecha_ultima_verificacion' => $row['fecha_ultima_verificacion'],
            
            // Calificaciones (manejar nulls)
            'calificacion_promedio' => floatval($row['calificacion_promedio'] ?? 0),
            'total_calificaciones' => intval($row['total_calificaciones'] ?? 0),
            'total_viajes' => intval($row['total_viajes'] ?? 0),
            
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
                'total' => $total_conductores,
                'total_pages' => ceil($total_conductores / $per_page),
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
