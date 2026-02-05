<?php
/**
 * Servicio de Concurrencia para Viajes
 * 
 * Proporciona:
 * - Bloqueo optimista con versionamiento
 * - Bloqueos distribuidos para operaciones críticas
 * - Operaciones atómicas con idempotencia
 * - Detección y resolución de conflictos
 */

require_once __DIR__ . '/database.php';

class ConcurrencyService {
    private $db;
    private $lockHolder;
    
    public function __construct($connection = null) {
        if ($connection) {
            $this->db = $connection;
        } else {
            $database = new Database();
            $this->db = $database->getConnection();
        }
        
        // Identificador único para este proceso/request
        $this->lockHolder = $this->generateLockHolder();
    }
    
    /**
     * Genera un identificador único para este holder de lock
     */
    private function generateLockHolder(): string {
        $components = [
            gethostname() ?: 'unknown',
            getmypid() ?: rand(1000, 9999),
            uniqid('', true)
        ];
        return substr(implode('_', $components), 0, 100);
    }
    
    /**
     * Adquiere un lock distribuido para un recurso
     * 
     * @param string $resourceType Tipo de recurso ('solicitud', 'conductor', 'pago')
     * @param int $resourceId ID del recurso
     * @param int $durationSeconds Duración del lock en segundos
     * @return bool True si se adquirió el lock
     */
    public function acquireLock(string $resourceType, int $resourceId, int $durationSeconds = 30): bool {
        try {
            // Limpiar locks expirados primero
            $this->cleanExpiredLocks();
            
            // Calcular fecha de expiración
            $expiresAt = date('Y-m-d H:i:s', time() + $durationSeconds);
            
            // Intentar insertar lock
            $stmt = $this->db->prepare("
                INSERT INTO distributed_locks (resource_type, resource_id, lock_holder, expires_at, lock_reason)
                VALUES (:type, :id, :holder, :expires, 'operation')
                ON CONFLICT (resource_type, resource_id) DO NOTHING
            ");
            
            $stmt->execute([
                ':type' => $resourceType,
                ':id' => $resourceId,
                ':holder' => $this->lockHolder,
                ':expires' => $expiresAt
            ]);
            
            // Verificar si se adquirió
            $stmt = $this->db->prepare("
                SELECT lock_holder FROM distributed_locks 
                WHERE resource_type = :type AND resource_id = :id
            ");
            $stmt->execute([':type' => $resourceType, ':id' => $resourceId]);
            $result = $stmt->fetch();
            
            return $result && $result['lock_holder'] === $this->lockHolder;
            
        } catch (Exception $e) {
            error_log("Error adquiriendo lock: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Libera un lock distribuido
     */
    public function releaseLock(string $resourceType, int $resourceId): bool {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM distributed_locks 
                WHERE resource_type = :type AND resource_id = :id AND lock_holder = :holder
            ");
            $stmt->execute([
                ':type' => $resourceType,
                ':id' => $resourceId,
                ':holder' => $this->lockHolder
            ]);
            
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("Error liberando lock: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Limpia locks expirados
     */
    public function cleanExpiredLocks(): void {
        try {
            $this->db->exec("DELETE FROM distributed_locks WHERE expires_at < NOW()");
        } catch (Exception $e) {
            error_log("Error limpiando locks: " . $e->getMessage());
        }
    }
    
    /**
     * Actualiza una solicitud con optimistic locking
     * 
     * @param int $solicitudId ID de la solicitud
     * @param array $updates Campos a actualizar
     * @param int|null $expectedVersion Versión esperada (null para ignorar)
     * @param string|null $idempotencyKey Clave para evitar duplicados
     * @return array Resultado con success, version, conflict
     */
    public function updateSolicitudWithLock(
        int $solicitudId,
        array $updates,
        ?int $expectedVersion = null,
        ?string $idempotencyKey = null
    ): array {
        try {
            // Verificar idempotencia primero
            if ($idempotencyKey) {
                $stmt = $this->db->prepare("
                    SELECT last_operation_key, estado FROM solicitudes_servicio WHERE id = :id
                ");
                $stmt->execute([':id' => $solicitudId]);
                $current = $stmt->fetch();
                
                if ($current && $current['last_operation_key'] === $idempotencyKey) {
                    return [
                        'success' => true,
                        'message' => 'Operación ya procesada (idempotente)',
                        'idempotent' => true,
                        'estado' => $current['estado']
                    ];
                }
            }
            
            // Construir query dinámico
            $setClauses = [];
            $params = [':id' => $solicitudId];
            
            foreach ($updates as $field => $value) {
                // Sanitizar nombre de campo
                $safeField = preg_replace('/[^a-zA-Z_]/', '', $field);
                $setClauses[] = "$safeField = :$safeField";
                $params[":$safeField"] = $value;
            }
            
            // Agregar clave de idempotencia
            if ($idempotencyKey) {
                $setClauses[] = "last_operation_key = :idempkey";
                $params[':idempkey'] = $idempotencyKey;
            }
            
            // Agregar timestamp de sincronización
            $setClauses[] = "last_sync_at = NOW()";
            
            // Construir WHERE con verificación de versión
            $where = "id = :id";
            if ($expectedVersion !== null) {
                $where .= " AND version = :expected_version";
                $params[':expected_version'] = $expectedVersion;
            }
            
            $sql = "UPDATE solicitudes_servicio SET " . implode(', ', $setClauses) . " WHERE $where RETURNING version, estado";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            if (!$result) {
                // No se actualizó - posible conflicto de versión
                $stmt = $this->db->prepare("SELECT version, estado FROM solicitudes_servicio WHERE id = :id");
                $stmt->execute([':id' => $solicitudId]);
                $current = $stmt->fetch();
                
                return [
                    'success' => false,
                    'message' => 'Conflicto de versión - la solicitud fue modificada',
                    'conflict' => true,
                    'expected_version' => $expectedVersion,
                    'actual_version' => $current['version'] ?? null,
                    'actual_estado' => $current['estado'] ?? null
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Actualización exitosa',
                'version' => $result['version'],
                'estado' => $result['estado']
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Acepta una solicitud de viaje con manejo de concurrencia robusto
     * 
     * @param int $solicitudId ID de la solicitud
     * @param int $conductorId ID del conductor
     * @param string $idempotencyKey Clave única para evitar doble aceptación
     * @return array Resultado de la operación
     */
    public function acceptTripConcurrent(
        int $solicitudId,
        int $conductorId,
        string $idempotencyKey
    ): array {
        // Intentar adquirir lock
        if (!$this->acquireLock('solicitud', $solicitudId, 10)) {
            return [
                'success' => false,
                'message' => 'La solicitud está siendo procesada por otro conductor',
                'retry' => true
            ];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Verificar idempotencia
            $stmt = $this->db->prepare("
                SELECT last_operation_key, estado, version 
                FROM solicitudes_servicio 
                WHERE id = :id FOR UPDATE
            ");
            $stmt->execute([':id' => $solicitudId]);
            $solicitud = $stmt->fetch();
            
            if (!$solicitud) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Solicitud no encontrada'];
            }
            
            // Verificar idempotencia
            if ($solicitud['last_operation_key'] === $idempotencyKey) {
                $this->db->commit();
                return [
                    'success' => true,
                    'message' => 'Solicitud ya aceptada por ti',
                    'idempotent' => true
                ];
            }
            
            // Verificar estado
            if ($solicitud['estado'] !== 'pendiente') {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => 'La solicitud ya fue aceptada por otro conductor',
                    'current_estado' => $solicitud['estado']
                ];
            }
            
            // Verificar conductor
            $stmt = $this->db->prepare("
                SELECT dc.disponible, dc.estado_verificacion
                FROM detalles_conductor dc
                WHERE dc.usuario_id = :conductor_id
            ");
            $stmt->execute([':conductor_id' => $conductorId]);
            $conductor = $stmt->fetch();
            
            if (!$conductor || !$conductor['disponible']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Conductor no disponible'];
            }
            
            if ($conductor['estado_verificacion'] !== 'aprobado') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Conductor no verificado'];
            }
            
            // Actualizar solicitud
            $stmt = $this->db->prepare("
                UPDATE solicitudes_servicio 
                SET estado = 'aceptada',
                    aceptado_en = NOW(),
                    last_operation_key = :idem_key,
                    last_sync_at = NOW()
                WHERE id = :id AND estado = 'pendiente'
            ");
            $stmt->execute([':id' => $solicitudId, ':idem_key' => $idempotencyKey]);
            
            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'No se pudo actualizar - estado cambió'];
            }
            
            // Crear asignación
            $stmt = $this->db->prepare("
                INSERT INTO asignaciones_conductor (solicitud_id, conductor_id, asignado_en, estado)
                VALUES (:sol_id, :cond_id, NOW(), 'asignado')
            ");
            $stmt->execute([':sol_id' => $solicitudId, ':cond_id' => $conductorId]);
            
            // Marcar conductor como no disponible
            $stmt = $this->db->prepare("
                UPDATE detalles_conductor SET disponible = 0 WHERE usuario_id = :cond_id
            ");
            $stmt->execute([':cond_id' => $conductorId]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Viaje aceptado exitosamente',
                'solicitud_id' => $solicitudId
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        } finally {
            $this->releaseLock('solicitud', $solicitudId);
        }
    }
    
    /**
     * Completa un viaje con manejo de concurrencia
     */
    public function completeTripConcurrent(
        int $solicitudId,
        int $conductorId,
        float $distanciaKm,
        int $duracionMin,
        string $idempotencyKey
    ): array {
        if (!$this->acquireLock('solicitud', $solicitudId, 30)) {
            return [
                'success' => false,
                'message' => 'Operación en progreso, espera un momento',
                'retry' => true
            ];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Verificar y actualizar con lock de fila
            $stmt = $this->db->prepare("
                SELECT s.*, ac.conductor_id as assigned_conductor
                FROM solicitudes_servicio s
                LEFT JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id 
                    AND ac.estado IN ('asignado', 'llegado', 'en_curso')
                WHERE s.id = :id FOR UPDATE
            ");
            $stmt->execute([':id' => $solicitudId]);
            $solicitud = $stmt->fetch();
            
            if (!$solicitud) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Solicitud no encontrada'];
            }
            
            // Verificar idempotencia
            if ($solicitud['last_operation_key'] === $idempotencyKey) {
                $this->db->commit();
                return [
                    'success' => true,
                    'message' => 'Viaje ya completado',
                    'idempotent' => true
                ];
            }
            
            // Verificar que el conductor correcto está completando
            if ($solicitud['assigned_conductor'] != $conductorId) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => 'No tienes permiso para completar este viaje'
                ];
            }
            
            // Verificar estado válido para completar
            $estadosValidos = ['aceptada', 'conductor_llego', 'en_curso'];
            if (!in_array($solicitud['estado'], $estadosValidos)) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => 'El viaje no puede ser completado en su estado actual: ' . $solicitud['estado']
                ];
            }
            
            // Completar viaje
            $stmt = $this->db->prepare("
                UPDATE solicitudes_servicio 
                SET estado = 'completada',
                    completado_en = NOW(),
                    entregado_en = NOW(),
                    distancia_recorrida = :distancia,
                    tiempo_transcurrido = :tiempo,
                    last_operation_key = :idem_key,
                    last_sync_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $solicitudId,
                ':distancia' => $distanciaKm,
                ':tiempo' => $duracionMin,
                ':idem_key' => $idempotencyKey
            ]);
            
            // Actualizar asignación
            $stmt = $this->db->prepare("
                UPDATE asignaciones_conductor 
                SET estado = 'completado' 
                WHERE solicitud_id = :sol_id AND conductor_id = :cond_id
            ");
            $stmt->execute([':sol_id' => $solicitudId, ':cond_id' => $conductorId]);
            
            // Liberar conductor
            $stmt = $this->db->prepare("
                UPDATE detalles_conductor 
                SET disponible = 1, total_viajes = COALESCE(total_viajes, 0) + 1 
                WHERE usuario_id = :cond_id
            ");
            $stmt->execute([':cond_id' => $conductorId]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Viaje completado exitosamente'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        } finally {
            $this->releaseLock('solicitud', $solicitudId);
        }
    }
    
    /**
     * Registra un log de sincronización para debugging
     */
    public function logSync(
        int $solicitudId,
        string $operation,
        ?int $clientVersion,
        ?int $serverVersion,
        bool $wasConflict,
        ?string $resolution = null,
        ?array $details = null
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sync_log 
                (solicitud_id, operation, client_version, server_version, was_conflict, resolution, details)
                VALUES (:sol_id, :op, :client_v, :server_v, :conflict, :resolution, :details)
            ");
            $stmt->execute([
                ':sol_id' => $solicitudId,
                ':op' => $operation,
                ':client_v' => $clientVersion,
                ':server_v' => $serverVersion,
                ':conflict' => $wasConflict ? 'true' : 'false',
                ':resolution' => $resolution,
                ':details' => $details ? json_encode($details) : null
            ]);
        } catch (Exception $e) {
            error_log("Error logging sync: " . $e->getMessage());
        }
    }
}
