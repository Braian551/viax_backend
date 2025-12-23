<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $conductor_id = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Obtener información básica del conductor desde tabla usuarios
    $query = "SELECT 
                u.id,
                u.nombre,
                u.apellido,
                u.email,
                u.telefono,
                u.foto_perfil,
                u.es_activo,
                u.fecha_registro
              FROM usuarios u
              WHERE u.id = :conductor_id AND u.tipo_usuario = 'conductor'";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt->execute();

    $conductor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conductor) {
        throw new Exception('Conductor no encontrado');
    }

    // Obtener detalles adicionales del conductor desde detalles_conductor
    $query_detalles = "SELECT 
                        dc.licencia_conduccion,
                        dc.licencia_vencimiento,
                        dc.vehiculo_tipo,
                        dc.vehiculo_marca,
                        dc.vehiculo_modelo,
                        dc.vehiculo_placa,
                        dc.vehiculo_color,
                        dc.vehiculo_anio,
                        dc.calificacion_promedio,
                        dc.total_viajes,
                        dc.disponible,
                        dc.latitud_actual,
                        dc.longitud_actual,
                        dc.estado_verificacion,
                        dc.fecha_ultima_verificacion
                       FROM detalles_conductor dc
                       WHERE dc.usuario_id = :conductor_id";
    
    $stmt_detalles = $db->prepare($query_detalles);
    $stmt_detalles->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_detalles->execute();

    $detalles = $stmt_detalles->fetch(PDO::FETCH_ASSOC);

    // Combinar información
    if ($detalles) {
        $conductor = array_merge($conductor, $detalles);
    } else {
        // Si no existe en detalles_conductor, agregar valores por defecto
        $conductor['licencia_conduccion'] = null;
        $conductor['licencia_vencimiento'] = null;
        $conductor['vehiculo_tipo'] = null;
        $conductor['vehiculo_marca'] = null;
        $conductor['vehiculo_modelo'] = null;
        $conductor['vehiculo_placa'] = null;
        $conductor['vehiculo_color'] = null;
        $conductor['vehiculo_anio'] = null;
        $conductor['calificacion_promedio'] = 0.0;
        $conductor['total_viajes'] = 0;
        $conductor['disponible'] = 0;
        $conductor['latitud_actual'] = null;
        $conductor['longitud_actual'] = null;
        $conductor['estado_verificacion'] = 'pendiente';
        $conductor['fecha_ultima_verificacion'] = null;
    }

    // Obtener ubicación del usuario desde ubicaciones_usuario
    $query_ubicacion = "SELECT 
                         direccion,
                         ciudad,
                         departamento,
                         pais,
                         latitud,
                         longitud
                        FROM ubicaciones_usuario
                        WHERE usuario_id = :conductor_id AND es_principal = 1
                        LIMIT 1";
    
    $stmt_ubicacion = $db->prepare($query_ubicacion);
    $stmt_ubicacion->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_ubicacion->execute();

    $ubicacion = $stmt_ubicacion->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'conductor' => $conductor,
        'ubicacion' => $ubicacion ?: null,
        'message' => 'Información del conductor obtenida exitosamente'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
