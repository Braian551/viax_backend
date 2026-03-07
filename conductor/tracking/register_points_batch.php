<?php
/**
 * API: Registrar puntos de tracking en lote
 * Endpoint: conductor/tracking/register_points_batch.php
 * Método: POST
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
require_once __DIR__ . '/tracking_ingest_service.php';

/**
 * Valida que el payload JSON exista y sea objeto.
 */
function readJsonInput(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new Exception('JSON inválido');
    }
    return $data;
}

try {
    $input = readJsonInput();

    $solicitudId = isset($input['solicitud_id']) ? intval($input['solicitud_id']) : 0;
    $conductorId = isset($input['conductor_id']) ? intval($input['conductor_id']) : 0;
    $puntos = isset($input['puntos']) && is_array($input['puntos']) ? $input['puntos'] : [];

    if ($solicitudId <= 0 || $conductorId <= 0) {
        throw new Exception('solicitud_id y conductor_id son requeridos');
    }

    if (empty($puntos)) {
        throw new Exception('puntos es requerido y debe contener al menos un punto');
    }

    if (count($puntos) > 120) {
        throw new Exception('Lote demasiado grande. Máximo 120 puntos por request');
    }

    // Protección básica anti-abuso: evita lotes con contenido no estructurado.
    foreach ($puntos as $punto) {
        if (!is_array($punto)) {
            throw new Exception('Formato de puntos inválido');
        }
    }

    usort($puntos, static function($a, $b) {
        $ta = intval($a['tiempo_transcurrido_seg'] ?? 0);
        $tb = intval($b['tiempo_transcurrido_seg'] ?? 0);
        return $ta <=> $tb;
    });

    $database = new Database();
    $db = $database->getConnection();

    $db->beginTransaction();
    $resultado = processTrackingPoints($db, $solicitudId, $conductorId, $puntos);
    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Lote de tracking procesado',
        'data' => [
            'puntos_insertados' => $resultado['inserted'],
            'puntos_descartados' => $resultado['skipped'],
            'distancia_acumulada_km' => $resultado['distancia_acumulada_km'],
            'tiempo_transcurrido_seg' => $resultado['tiempo_transcurrido_seg'],
            'precio_parcial' => $resultado['precio_parcial'],
        ],
    ]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(400);
    error_log('register_points_batch.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
