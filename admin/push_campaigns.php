<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';

try {
    $db = (new Database())->getConnection();
    SessionAccess::requireAdmin($db);
    if (Request::method() !== 'GET') {
        header('Allow: GET');
        sendJsonResponse(false, 'Las campañas están disponibles en modo consulta.', [], 405);
    }
    $campaignId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]) : null;
    if ($campaignId === false) {
        sendJsonResponse(false, 'Identificador de campaña inválido.', [], 400);
    }
    $where = $campaignId === null ? '' : 'WHERE np.id = ?';
    $stmt = $db->prepare(
        "SELECT np.id, np.titulo, np.mensaje, np.rol, np.empresa_id, np.activo, np.hora_envio,
                np.fecha_inicio, np.fecha_fin, np.tipo, np.timezone_name, np.enviadas, np.abiertas,
                recent.slot_programado, recent.pushes_entregados
           FROM notificaciones_push np
           LEFT JOIN LATERAL (
               SELECT slot_programado, pushes_entregados
                 FROM notificaciones_push_ejecuciones
                WHERE notificacion_push_id = np.id
                ORDER BY slot_programado DESC, id DESC LIMIT 1
           ) recent ON true
           $where
          ORDER BY np.activo DESC, np.updated_at DESC, np.id DESC"
    );
    $stmt->execute($campaignId === null ? [] : [$campaignId]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($campaigns as &$row) {
        $row['activo'] = filter_var($row['activo'], FILTER_VALIDATE_BOOLEAN);
        $row['ultima_ejecucion'] = $row['slot_programado'] === null ? null : [
            'slot_programado' => $row['slot_programado'],
            'pushes_entregados' => (int)$row['pushes_entregados'],
        ];
        unset($row['slot_programado'], $row['pushes_entregados']);
    }
    unset($row);
    if ($campaignId !== null) {
        if (!$campaigns) {
            sendJsonResponse(false, 'Campaña no encontrada.', [], 404);
        }
        sendJsonResponse(true, 'Campaña obtenida.', ['campaign' => $campaigns[0], 'can_manage' => false]);
    }

    sendJsonResponse(true, 'Campañas obtenidas.', ['campaigns' => $campaigns, 'can_manage' => false]);
} catch (Throwable $error) {
    error_log('VIAX push campaign listing: ' . get_class($error));
    sendJsonResponse(false, 'No se pudieron cargar las campañas.', [], 500);
}
