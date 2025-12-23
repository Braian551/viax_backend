<?php
/**
 * Endpoint: Obtener Conductores Favoritos
 * 
 * GET/POST /user/get_favorite_drivers.php
 * Body: { "usuario_id": int }
 * 
 * Retorna lista de conductores marcados como favoritos por el usuario
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../confianza/ConfianzaService.php';

try {
    // Soportar tanto GET como POST
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $usuarioId = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : null;
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        $usuarioId = isset($data['usuario_id']) ? (int)$data['usuario_id'] : null;
    }
    
    if (!$usuarioId) {
        throw new Exception('usuario_id es requerido');
    }
    
    // Obtener favoritos
    $confianzaService = new ConfianzaService();
    $favoritos = $confianzaService->obtenerFavoritos($usuarioId);
    
    // Formatear respuesta
    $favoritosFormateados = array_map(function($fav) {
        return [
            'conductor_id' => (int)$fav['conductor_id'],
            'nombre' => $fav['nombre'],
            'apellido' => $fav['apellido'],
            'nombre_completo' => trim($fav['nombre'] . ' ' . $fav['apellido']),
            'foto_perfil' => $fav['foto_perfil'],
            'vehiculo' => [
                'tipo' => $fav['vehiculo_tipo'],
                'marca' => $fav['vehiculo_marca'],
                'modelo' => $fav['vehiculo_modelo'],
                'placa' => $fav['vehiculo_placa'],
            ],
            'calificacion_promedio' => (float)$fav['calificacion_promedio'],
            'total_viajes' => (int)$fav['total_viajes'],
            'viajes_contigo' => (int)$fav['viajes_contigo'],
            'score_confianza' => (float)$fav['score_confianza'],
            'fecha_marcado' => $fav['fecha_marcado'],
        ];
    }, $favoritos);
    
    echo json_encode([
        'success' => true,
        'total' => count($favoritosFormateados),
        'favoritos' => $favoritosFormateados
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
