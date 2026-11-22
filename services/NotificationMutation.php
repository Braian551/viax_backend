<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';

final class NotificationMutation
{
    public static function run(bool $delete): void
    {
        try {
            $db = (new Database())->getConnection();
            $user = SessionAccess::requireUser($db);
            $userId = (int)$user['id'];
            $input = getJsonInput();
            SessionAccess::requireOwner($input, $userId);

            $all = ($input[$delete ? 'delete_all' : 'mark_all'] ?? false) === true;
            $ids = $all ? [] : self::requestedIds($input);
            $where = 'usuario_id = ? AND eliminada = FALSE';
            $params = [$userId];
            if (!$all) {
                $where .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }

            $change = $delete
                ? 'eliminada = TRUE, eliminada_en = CURRENT_TIMESTAMP'
                : 'leida = TRUE, leida_en = COALESCE(leida_en, CURRENT_TIMESTAMP)';
            $db->beginTransaction();
            $stmt = $db->prepare("UPDATE notificaciones_usuario SET $change WHERE $where");
            $stmt->execute($params);
            $affected = $stmt->rowCount();
            if (!$all && $affected !== count($ids)) {
                $db->rollBack();
                sendJsonResponse(false, 'Notificación no encontrada.', [], 404, 'NOT_FOUND');
            }

            $count = $db->prepare('SELECT contar_notificaciones_no_leidas(?)');
            $count->execute([$userId]);
            $unread = (int)$count->fetchColumn();
            $db->commit();
            echo json_encode(['success' => true, 'affected' => $affected, 'no_leidas' => $unread]);
        } catch (Throwable $error) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('VIAX notification update: ' . get_class($error));
            sendJsonResponse(false, 'No se pudo actualizar la notificación.', [], 500);
        }
    }

    private static function requestedIds(array $input): array
    {
        $ids = isset($input['notification_id']) ? [$input['notification_id']] : ($input['notification_ids'] ?? null);
        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            sendJsonResponse(false, 'Especifica entre 1 y 100 notificaciones.', [], 400);
        }
        $ids = array_map(static fn ($value) => filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]), $ids);
        if (in_array(false, $ids, true)) {
            sendJsonResponse(false, 'Identificador de notificación inválido.', [], 400);
        }
        return array_values(array_unique($ids));
    }
}
