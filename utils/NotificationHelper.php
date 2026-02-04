<?php
/**
 * NotificationHelper.php
 * Helper para crear notificaciones de forma sencilla desde cualquier parte del backend
 */

require_once __DIR__ . '/../config/database.php';

class NotificationHelper {
    private static $conn = null;
    
    /**
     * Obtiene la conexión a la base de datos
     */
    private static function getConnection() {
        if (self::$conn === null) {
            $database = new Database();
            self::$conn = $database->getConnection();
        }
        return self::$conn;
    }
    
    /**
     * Crea una notificación para un usuario
     * 
     * @param int $usuarioId ID del usuario
     * @param string $tipo Código del tipo de notificación
     * @param string $titulo Título de la notificación
     * @param string $mensaje Mensaje de la notificación
     * @param string|null $referenciaTipo Tipo de entidad relacionada
     * @param int|null $referenciaId ID de la entidad relacionada
     * @param array $data Datos adicionales
     * @return int|false ID de la notificación creada o false si falla
     */
    public static function crear(
        int $usuarioId,
        string $tipo,
        string $titulo,
        string $mensaje,
        ?string $referenciaTipo = null,
        ?int $referenciaId = null,
        array $data = []
    ) {
        try {
            $conn = self::getConnection();
            
            $query = "SELECT crear_notificacion(:usuario_id, :tipo, :titulo, :mensaje, :ref_tipo, :ref_id, :data) as id";
            $stmt = $conn->prepare($query);
            $stmt->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
            $stmt->bindValue(':tipo', $tipo);
            $stmt->bindValue(':titulo', $titulo);
            $stmt->bindValue(':mensaje', $mensaje);
            $stmt->bindValue(':ref_tipo', $referenciaTipo);
            $stmt->bindValue(':ref_id', $referenciaId, $referenciaId ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':data', json_encode($data));
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC)['id'];
        } catch (Exception $e) {
            error_log("Error creando notificación: " . $e->getMessage());
            return false;
        }
    }
    
    // =====================================================
    // MÉTODOS HELPER PARA TIPOS COMUNES
    // =====================================================
    
    /**
     * Notifica que un conductor aceptó el viaje
     */
    public static function viajeAceptado(int $clienteId, int $viajeId, string $conductorNombre) {
        return self::crear(
            $clienteId,
            'trip_accepted',
            '¡Conductor en camino!',
            "$conductorNombre ha aceptado tu solicitud de viaje. Te está esperando.",
            'viaje',
            $viajeId,
            ['conductor_nombre' => $conductorNombre]
        );
    }
    
    /**
     * Notifica que el viaje fue cancelado
     */
    public static function viajeCancelado(int $usuarioId, int $viajeId, string $motivo = '') {
        $mensaje = 'Tu viaje ha sido cancelado.';
        if (!empty($motivo)) {
            $mensaje .= " Motivo: $motivo";
        }
        
        return self::crear(
            $usuarioId,
            'trip_cancelled',
            'Viaje cancelado',
            $mensaje,
            'viaje',
            $viajeId
        );
    }
    
    /**
     * Notifica que el viaje fue completado
     */
    public static function viajeCompletado(int $clienteId, int $viajeId, float $precio) {
        return self::crear(
            $clienteId,
            'trip_completed',
            'Viaje completado',
            "Tu viaje ha finalizado exitosamente. Total: $" . number_format($precio, 0, ',', '.'),
            'viaje',
            $viajeId,
            ['precio' => $precio]
        );
    }
    
    /**
     * Notifica que el conductor llegó
     */
    public static function conductorLlego(int $clienteId, int $viajeId, string $conductorNombre) {
        return self::crear(
            $clienteId,
            'driver_arrived',
            'El conductor llegó',
            "$conductorNombre ha llegado a tu ubicación.",
            'viaje',
            $viajeId
        );
    }
    
    /**
     * Notifica que el conductor está esperando
     */
    public static function conductorEsperando(int $clienteId, int $viajeId, string $conductorNombre) {
        return self::crear(
            $clienteId,
            'driver_waiting',
            'Tu conductor te espera',
            "$conductorNombre te está esperando. Por favor acércate.",
            'viaje',
            $viajeId
        );
    }
    
    /**
     * Notifica que el pago fue recibido
     */
    public static function pagoRecibido(int $usuarioId, int $pagoId, float $monto) {
        return self::crear(
            $usuarioId,
            'payment_received',
            'Pago confirmado',
            "Tu pago de $" . number_format($monto, 0, ',', '.') . " ha sido procesado correctamente.",
            'pago',
            $pagoId,
            ['monto' => $monto]
        );
    }
    
    /**
     * Notifica que hay un pago pendiente
     */
    public static function pagoPendiente(int $usuarioId, int $viajeId, float $monto) {
        return self::crear(
            $usuarioId,
            'payment_pending',
            'Pago pendiente',
            "Tienes un pago pendiente de $" . number_format($monto, 0, ',', '.') . " por confirmar.",
            'viaje',
            $viajeId,
            ['monto' => $monto]
        );
    }
    
    /**
     * Notifica una promoción
     */
    public static function promocion(int $usuarioId, string $titulo, string $descripcion, ?string $codigo = null) {
        return self::crear(
            $usuarioId,
            'promo',
            $titulo,
            $descripcion,
            null,
            null,
            $codigo ? ['codigo' => $codigo] : []
        );
    }
    
    /**
     * Notifica una calificación recibida
     */
    public static function calificacionRecibida(int $usuarioId, int $viajeId, int $estrellas) {
        return self::crear(
            $usuarioId,
            'rating_received',
            'Nueva calificación',
            "Has recibido una calificación de $estrellas " . ($estrellas == 1 ? 'estrella' : 'estrellas') . ".",
            'viaje',
            $viajeId,
            ['estrellas' => $estrellas]
        );
    }
    
    /**
     * Notifica un nuevo mensaje de chat
     */
    public static function nuevoMensaje(int $usuarioId, int $chatId, string $remitente, string $preview) {
        return self::crear(
            $usuarioId,
            'chat_message',
            "Mensaje de $remitente",
            strlen($preview) > 100 ? substr($preview, 0, 97) . '...' : $preview,
            'chat',
            $chatId
        );
    }
    
    /**
     * Notifica actualización de disputa
     */
    public static function actualizacionDisputa(int $usuarioId, int $disputaId, string $estado) {
        $mensajes = [
            'abierta' => 'Tu disputa ha sido registrada y está siendo revisada.',
            'en_revision' => 'Tu disputa está siendo revisada por nuestro equipo.',
            'resuelta' => 'Tu disputa ha sido resuelta. Revisa los detalles.',
            'cerrada' => 'Tu disputa ha sido cerrada.'
        ];
        
        return self::crear(
            $usuarioId,
            'dispute_update',
            'Actualización de disputa',
            $mensajes[$estado] ?? "Tu disputa ha sido actualizada a estado: $estado",
            'disputa',
            $disputaId,
            ['estado' => $estado]
        );
    }
    
    /**
     * Notifica un mensaje del sistema
     */
    public static function sistema(int $usuarioId, string $titulo, string $mensaje) {
        return self::crear(
            $usuarioId,
            'system',
            $titulo,
            $mensaje
        );
    }
    
    /**
     * Envía una notificación a múltiples usuarios
     */
    public static function enviarMasivo(
        array $usuarioIds,
        string $tipo,
        string $titulo,
        string $mensaje,
        array $data = []
    ) {
        $resultados = [];
        foreach ($usuarioIds as $usuarioId) {
            $resultados[$usuarioId] = self::crear($usuarioId, $tipo, $titulo, $mensaje, null, null, $data);
        }
        return $resultados;
    }
}
