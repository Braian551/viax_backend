<?php
/**
 * Endpoint: actualizar_ubicacion.php
 *
 * Este endpoint es usado por la app Flutter de conductor para enviar GPS
 * frecuente (cada pocos segundos). Para alto rendimiento:
 * - Escribe siempre en Redis (driver_location:{conductor_id}).
 * - Persiste en BD de forma periódica para reducir carga de escritura.
 * - Mantiene compatibilidad total del contrato JSON existente.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/driver_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

/** Valida latitud en rango geográfico permitido. */
function isValidLat(float $lat): bool
{
    return $lat >= -90.0 && $lat <= 90.0;
}

/** Valida longitud en rango geográfico permitido. */
function isValidLng(float $lng): bool
{
    return $lng >= -180.0 && $lng <= 180.0;
}

/**
 * Guarda ubicación en Redis para lectura ultra-rápida en tiempo real.
 *
 * Claves:
 * - driver_location:{id}
 * - active_drivers (set)
 */
function writeLocationToRedis(int $conductorId, float $lat, float $lng, ?float $speed): void
{
    $payload = json_encode([
        'lat' => $lat,
        'lng' => $lng,
        'speed' => $speed,
        'timestamp' => time(),
    ]);

    Cache::set("driver_location:{$conductorId}", (string) $payload, 30);
    Cache::sAdd('active_drivers', (string) $conductorId);
    DriverGeoService::upsertDriverLocation($conductorId, $lat, $lng, $speed);
}

/**
 * Determina si toca persistir en BD según un throttle por conductor.
 *
 * Esto reduce escrituras intensivas sin perder frescura en tiempo real,
 * ya que la app consumidora lee primero Redis.
 */
function shouldPersistToDatabase(int $conductorId, int $intervalSeconds = 12): bool
{
    $key = "driver_location:last_persist:{$conductorId}";
    $last = Cache::get($key);
    $now = time();

    if ($last === null) {
        Cache::set($key, (string) $now, $intervalSeconds + 5);
        return true;
    }

    $lastTs = (int) $last;
    if (($now - $lastTs) >= $intervalSeconds) {
        Cache::set($key, (string) $now, $intervalSeconds + 5);
        return true;
    }

    return false;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $conductorId = isset($input['conductor_id']) ? (int) $input['conductor_id'] : 0;
    $latitud = isset($input['latitud']) ? (float) $input['latitud'] : null;
    $longitud = isset($input['longitud']) ? (float) $input['longitud'] : null;
    $velocidad = isset($input['velocidad']) ? (float) $input['velocidad'] : null;

    if ($conductorId <= 0) {
        throw new Exception('ID de conductor inválido');
    }
    if ($latitud === null || $longitud === null) {
        throw new Exception('Latitud y longitud son requeridas');
    }
    if (!isValidLat($latitud) || !isValidLng($longitud)) {
        throw new Exception('Coordenadas inválidas');
    }

    // Escritura rápida en cache (camino crítico realtime).
    writeLocationToRedis($conductorId, $latitud, $longitud, $velocidad);
    DriverGeoService::setDriverState($conductorId, 'available');

    // Persistencia periódica en BD para descargar I/O.
    $persistirBd = shouldPersistToDatabase($conductorId);

    if ($persistirBd) {
        $database = new Database();
        $db = $database->getConnection();

        $query = "UPDATE detalles_conductor
                  SET latitud_actual = :latitud,
                      longitud_actual = :longitud,
                      ultima_actualizacion = NOW()
                  WHERE usuario_id = :conductor_id";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':latitud', $latitud);
        $stmt->bindParam(':longitud', $longitud);
        $stmt->bindParam(':conductor_id', $conductorId, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            $queryInsert = "INSERT INTO detalles_conductor
                            (usuario_id, latitud_actual, longitud_actual, fecha_creacion, ultima_actualizacion)
                            VALUES (:conductor_id, :latitud, :longitud, NOW(), NOW())";

            $stmtInsert = $db->prepare($queryInsert);
            $stmtInsert->bindParam(':conductor_id', $conductorId, PDO::PARAM_INT);
            $stmtInsert->bindParam(':latitud', $latitud);
            $stmtInsert->bindParam(':longitud', $longitud);
            $stmtInsert->execute();
        }

        // Importante: este endpoint SOLO actualiza ubicación del conductor.
        // Las métricas canónicas de distancia/tiempo se calculan en
        // /driver/tracking/update y en finalize para evitar inflado por payloads legacy.
    }

    echo json_encode([
        'success' => true,
        'message' => 'Ubicación actualizada exitosamente',
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log('actualizar_ubicacion.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
