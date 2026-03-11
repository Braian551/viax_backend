<?php
/**
 * Archiva puntos de tracking antiguos (>30 días) para reducir costo de consultas hot.
 */

require_once __DIR__ . '/../config/app.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $cutoffEpoch = time() - (30 * 24 * 3600);

    $db->beginTransaction();

    $insert = $db->prepare(
        'INSERT INTO trip_tracking_points_archive (id, trip_id, lat, lng, speed, heading, "timestamp", created_at, archived_at)
         SELECT id, trip_id, lat, lng, speed, heading, "timestamp", created_at, NOW()
         FROM trip_tracking_points
         WHERE "timestamp" < :cutoff
         ON CONFLICT (id) DO NOTHING'
    );
    $insert->execute([':cutoff' => $cutoffEpoch]);

    $delete = $db->prepare('DELETE FROM trip_tracking_points WHERE "timestamp" < :cutoff');
    $delete->execute([':cutoff' => $cutoffEpoch]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Archivado ejecutado',
        'cutoff_epoch' => $cutoffEpoch,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
