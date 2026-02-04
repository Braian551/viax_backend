<?php
require_once '../config/database.php';

header('Content-Type: application/json');

$userId = $_GET['user_id'] ?? null;
$role = $_GET['role'] ?? 'cliente'; // 'cliente' or 'conductor'

if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'ID de usuario requerido']);
    exit;
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    // Estados considerados como "viaje activo"
    // 'pendiente': Esperando conductor
    // 'aceptada': Conductor asignado, en camino a recoger (Para cliente y conductor)
    // 'en_camino': (Alias de aceptada en algunos contextos, pero verificamos)
    // 'conductor_llego': Conductor espera al cliente
    // 'en_curso': Viaje iniciado
    $activeStatuses = "'pendiente', 'aceptada', 'en_camino', 'conductor_llego', 'recogido', 'en_curso'";

    $sql = "";
    if ($role === 'conductor') {
        $sql = "SELECT s.*, ac.conductor_id 
                FROM solicitudes_servicio s
                INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
                WHERE ac.conductor_id = :userId 
                AND s.estado IN ($activeStatuses) 
                AND ac.estado IN ('asignado', 'llegado', 'en_curso')
                ORDER BY s.fecha_creacion DESC LIMIT 1";
    } else {
        $sql = "SELECT * FROM solicitudes_servicio 
                WHERE cliente_id = :userId 
                AND estado IN ($activeStatuses) 
                ORDER BY fecha_creacion DESC LIMIT 1";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':userId', $userId);
    $stmt->execute();
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($trip) {
        // Fetch conductor/client info details if needed, similar to get_trip_status
        // For now, returning the trip row is enough for Splash to identify ID and Status
        
        // Enhance with origin/destination nested structure to match expected format in Splash
        // Or Splash can handle flat structure if we update it?
        // Splash expects:
        // trip['conductor_id'], trip['cliente_id']
        // trip['origen']['latitud']... OR trip['latitud_origen']
        
        // Let's format it to match get_trip_status response structure partially
        $formattedTrip = $trip;
        $formattedTrip['origen'] = [
            'latitud' => $trip['latitud_recogida'],
            'longitud' => $trip['longitud_recogida'],
            'direccion' => $trip['direccion_recogida']
        ];
        $formattedTrip['destino'] = [
            'latitud' => $trip['latitud_destino'],
            'longitud' => $trip['longitud_destino'],
            'direccion' => $trip['direccion_destino']
        ];
        
        // Add conductor info if user is client
        if ($role === 'cliente' && $trip['conductor_id']) {
            $stmtCond = $conn->prepare("SELECT id, nombre, apellido, telefono, foto_perfil, calificacion_promedio FROM usuarios WHERE id = ?");
            $stmtCond->execute([$trip['conductor_id']]);
            $conductor = $stmtCond->fetch(PDO::FETCH_ASSOC);
            $formattedTrip['conductor'] = $conductor; // Attach to trip or root? 
            // get_trip_status puts conductor info in root 'conductor' key mostly?
            // Actually Splash separates: tripStatus['trip'] and tripStatus['conductor']
        }

        $response = [
            'success' => true,
            'has_active' => true,
            'trip' => $formattedTrip
        ];
        
        if (isset($conductor)) {
             $response['conductor'] = $conductor;
        }

        echo json_encode($response);
    } else {
        echo json_encode(['success' => true, 'has_active' => false, 'message' => 'No hay viaje activo']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>
