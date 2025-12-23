<?php
/**
 * Endpoint: Calcular ConfianzaScore
 * 
 * POST /confianza/calculate_score.php
 * Body: { 
 *   "usuario_id": int, 
 *   "conductor_id": int,
 *   "latitud": float (opcional),
 *   "longitud": float (opcional)
 * }
 * 
 * Calcula y retorna el score de confianza entre un usuario y conductor
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ConfianzaService.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    if (!isset($data['usuario_id']) || !isset($data['conductor_id'])) {
        throw new Exception('Datos requeridos: usuario_id, conductor_id');
    }
    
    $usuarioId = (int)$data['usuario_id'];
    $conductorId = (int)$data['conductor_id'];
    $latitud = isset($data['latitud']) ? (float)$data['latitud'] : null;
    $longitud = isset($data['longitud']) ? (float)$data['longitud'] : null;
    
    $confianzaService = new ConfianzaService();
    
    // Calcular score
    $score = $confianzaService->calcularConfianzaScore(
        $usuarioId, 
        $conductorId, 
        $latitud, 
        $longitud
    );
    
    // Obtener info adicional
    $esFavorito = $confianzaService->esFavorito($usuarioId, $conductorId);
    
    // Obtener detalles del historial
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("
        SELECT 
            hc.total_viajes,
            hc.viajes_completados,
            hc.ultimo_viaje_fecha,
            dc.calificacion_promedio,
            dc.total_viajes as total_viajes_conductor
        FROM historial_confianza hc
        RIGHT JOIN detalles_conductor dc ON dc.usuario_id = ?
        WHERE hc.usuario_id = ? AND hc.conductor_id = ?
        OR (hc.usuario_id IS NULL AND dc.usuario_id = ?)
    ");
    $stmt->execute([$conductorId, $usuarioId, $conductorId, $conductorId]);
    $detalles = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'usuario_id' => $usuarioId,
        'conductor_id' => $conductorId,
        'score_confianza' => $score,
        'es_favorito' => $esFavorito,
        'desglose' => [
            'viajes_juntos' => (int)($detalles['viajes_completados'] ?? 0),
            'total_viajes_conductor' => (int)($detalles['total_viajes_conductor'] ?? 0),
            'calificacion_conductor' => (float)($detalles['calificacion_promedio'] ?? 0),
            'ultimo_viaje' => $detalles['ultimo_viaje_fecha'] ?? null,
            'bonus_favorito' => $esFavorito ? 100 : 0,
        ],
        'nivel_confianza' => $this->getNivelConfianza($score)
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getNivelConfianza($score) {
    if ($score >= 150) return ['nivel' => 'muy_alto', 'descripcion' => 'Conductor de extrema confianza'];
    if ($score >= 100) return ['nivel' => 'alto', 'descripcion' => 'Conductor favorito o muy confiable'];
    if ($score >= 50) return ['nivel' => 'medio', 'descripcion' => 'Conductor conocido'];
    if ($score >= 20) return ['nivel' => 'bajo', 'descripcion' => 'Algunos viajes previos'];
    return ['nivel' => 'nuevo', 'descripcion' => 'Sin historial'];
}
