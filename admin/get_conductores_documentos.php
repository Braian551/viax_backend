<?php
// Suprimir warnings y notices
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

// Activar temporalmente el reporte de errores para debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Crear conexión mysqli
$conn = new mysqli('localhost', 'root', 'root', 'viax');
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión a la base de datos: ' . $conn->connect_error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$conn->set_charset("utf8");

try {
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
    $stmt = $conn->prepare("SELECT tipo_usuario FROM usuarios WHERE id = ? AND tipo_usuario = 'administrador'");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Acceso denegado. Solo administradores pueden ver documentos.'
        ]);
        exit;
    }

    // Construir query
    $where_clauses = ["u.tipo_usuario = 'conductor'"];
    $params = [];
    $types = "";

    if ($conductor_id !== null) {
        $where_clauses[] = "dc.usuario_id = ?";
        $params[] = $conductor_id;
        $types .= "i";
    }

    if ($estado_verificacion !== null && in_array($estado_verificacion, ['pendiente', 'en_revision', 'aprobado', 'rechazado'])) {
        $where_clauses[] = "dc.estado_verificacion = ?";
        $params[] = $estado_verificacion;
        $types .= "s";
    }

    $where_sql = implode(' AND ', $where_clauses);
    $offset = ($page - 1) * $per_page;

    // Query para contar total
    $count_sql = "SELECT COUNT(*) as total 
                  FROM detalles_conductor dc
                  INNER JOIN usuarios u ON dc.usuario_id = u.id
                  WHERE $where_sql";

    $stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total_result = $stmt->get_result()->fetch_assoc();
    $total_conductores = $total_result['total'];

    // Query principal
    $sql = "SELECT 
                dc.*,
                u.nombre,
                u.apellido,
                u.email,
                u.telefono,
                u.foto_perfil,
                u.es_verificado,
                u.es_activo,
                u.fecha_registro as usuario_creado_en
            FROM detalles_conductor dc
            INNER JOIN usuarios u ON dc.usuario_id = u.id
            WHERE $where_sql
            ORDER BY dc.fecha_ultima_verificacion DESC, dc.creado_en DESC
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $conductores = [];
    while ($row = $result->fetch_assoc()) {
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
            'id' => $row['id'],
            'usuario_id' => $row['usuario_id'],
            
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
            
            // Vehículo
            'vehiculo_tipo' => $row['vehiculo_tipo'],
            'vehiculo_marca' => $row['vehiculo_marca'],
            'vehiculo_modelo' => $row['vehiculo_modelo'],
            'vehiculo_anio' => $row['vehiculo_anio'],
            'vehiculo_color' => $row['vehiculo_color'],
            'vehiculo_placa' => $row['vehiculo_placa'],
            
            // Seguros y documentos
            'aseguradora' => $row['aseguradora'],
            'numero_poliza_seguro' => $row['numero_poliza_seguro'],
            'vencimiento_seguro' => $row['vencimiento_seguro'],
            'seguro_foto_url' => $row['seguro_foto_url'],
            'soat_numero' => $row['soat_numero'],
            'soat_vencimiento' => $row['soat_vencimiento'],
            'soat_foto_url' => $row['soat_foto_url'],
            'tecnomecanica_numero' => $row['tecnomecanica_numero'],
            'tecnomecanica_vencimiento' => $row['tecnomecanica_vencimiento'],
            'tecnomecanica_foto_url' => $row['tecnomecanica_foto_url'],
            'tarjeta_propiedad_numero' => $row['tarjeta_propiedad_numero'],
            'tarjeta_propiedad_foto_url' => $row['tarjeta_propiedad_foto_url'],
            
            // Estado de aprobación
            'aprobado' => $row['aprobado'],
            'estado_aprobacion' => $row['estado_aprobacion'],
            'estado_verificacion' => $row['estado_verificacion'],
            'fecha_ultima_verificacion' => $row['fecha_ultima_verificacion'],
            
            // Calificaciones
            'calificacion_promedio' => floatval($row['calificacion_promedio']),
            'total_calificaciones' => intval($row['total_calificaciones']),
            
            // Ubicación y disponibilidad
            'disponible' => $row['disponible'],
            'latitud_actual' => $row['latitud_actual'],
            'longitud_actual' => $row['longitud_actual'],
            'ultima_actualizacion' => $row['ultima_actualizacion'],
            
            // Estadísticas
            'total_viajes' => intval($row['total_viajes']),
            
            // Fechas
            'creado_en' => $row['creado_en'],
            'actualizado_en' => $row['actualizado_en'],
            'fecha_creacion' => $row['fecha_creacion'],
            
            // Análisis de documentos
            'documentos_pendientes' => $pendientes,
            'documentos_completos' => $completos,
            'total_documentos_requeridos' => count($documentos_requeridos),
            'porcentaje_completitud' => round(($completos / count($documentos_requeridos)) * 100, 2),
            'documentos_vencidos' => $documentos_vencidos,
            'tiene_documentos_vencidos' => count($documentos_vencidos) > 0,
        ];
    }

    // Calcular estadísticas generales
    $estadisticas = [
        'total_conductores' => $total_conductores,
        'pendientes_verificacion' => 0,
        'en_revision' => 0,
        'aprobados' => 0,
        'rechazados' => 0,
        'con_documentos_vencidos' => 0,
    ];

    foreach ($conductores as $conductor) {
        $estado = $conductor['estado_verificacion'];
        if ($estado === 'pendiente') {
            $estadisticas['pendientes_verificacion']++;
        } else if (isset($estadisticas[$estado])) {
            $estadisticas[$estado]++;
        }
        
        if ($conductor['tiene_documentos_vencidos']) {
            $estadisticas['con_documentos_vencidos']++;
        }
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
