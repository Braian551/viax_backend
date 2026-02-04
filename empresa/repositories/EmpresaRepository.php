<?php
/**
 * EmpresaRepository.php
 * Handles all database operations for empresa registration
 * Single Responsibility: Data Persistence Layer
 */

class EmpresaRepository {
    
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Create a new empresa record
     * @return array with id and creado_en
     */
    public function createEmpresa($empresaData) {
        $query = "INSERT INTO empresas_transporte (
            nombre, nit, razon_social, email, telefono, telefono_secundario,
            direccion, municipio, departamento, representante_nombre,
            representante_telefono, representante_email, tipos_vehiculo,
            logo_url, descripcion, estado, verificada, notas_admin
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', false, ?)
        RETURNING id, creado_en";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            $empresaData['nombre'],
            $empresaData['nit'],
            $empresaData['razon_social'],
            $empresaData['email'],
            $empresaData['telefono'],
            $empresaData['telefono_secundario'],
            $empresaData['direccion'],
            $empresaData['municipio'],
            $empresaData['departamento'],
            $empresaData['representante_nombre'],
            $empresaData['representante_telefono'],
            $empresaData['representante_email'],
            $empresaData['tipos_vehiculo'],
            $empresaData['logo_url'],
            $empresaData['descripcion'],
            $empresaData['notas_admin']
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $empresaId = $result['id'];
        
        // Crear registro en empresas_contacto (tabla normalizada)
        $this->createEmpresaContacto($empresaId, $empresaData);
        
        // Configurar zona de operación inicial basada en el municipio
        $this->initializeZonaOperacion($empresaId, $empresaData['municipio'], $empresaData['departamento']);
        
        return $result;
    }
    
    /**
     * Crear registro de contacto para la empresa (tabla normalizada)
     */
    private function createEmpresaContacto($empresaId, $empresaData) {
        try {
            // Verificar si la tabla existe
            $checkTable = $this->db->query("SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' AND table_name = 'empresas_contacto'
            )");
            
            if (!$checkTable->fetchColumn()) {
                return; // Tabla no existe, salir silenciosamente
            }
            
            $query = "INSERT INTO empresas_contacto (
                empresa_id, email, telefono, telefono_secundario, 
                direccion, municipio, departamento, creado_en
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON CONFLICT (empresa_id) DO UPDATE SET
                email = EXCLUDED.email,
                telefono = EXCLUDED.telefono,
                telefono_secundario = EXCLUDED.telefono_secundario,
                direccion = EXCLUDED.direccion,
                municipio = EXCLUDED.municipio,
                departamento = EXCLUDED.departamento,
                actualizado_en = NOW()";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                $empresaId,
                $empresaData['email'] ?? null,
                $empresaData['telefono'] ?? null,
                $empresaData['telefono_secundario'] ?? null,
                $empresaData['direccion'] ?? null,
                $empresaData['municipio'] ?? null,
                $empresaData['departamento'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log("Error creando empresas_contacto: " . $e->getMessage());
        }
    }
    
    /**
     * Inicializar zona de operación basada en el municipio de la empresa
     */
    private function initializeZonaOperacion($empresaId, $municipio, $departamento = null) {
        try {
            if (empty($municipio)) {
                return;
            }
            
            // Verificar si la tabla existe
            $checkTable = $this->db->query("SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' AND table_name = 'empresas_configuracion'
            )");
            
            if (!$checkTable->fetchColumn()) {
                return; // Tabla no existe
            }
            
            // Construir array de zona de operación
            $zonas = [trim($municipio)];
            if (!empty($departamento)) {
                $zonas[] = trim($departamento);
            }
            
            // Formatear para PostgreSQL array
            $zonaPostgres = '{' . implode(',', array_map(function($m) {
                return '"' . str_replace('"', '\"', $m) . '"';
            }, array_unique(array_filter($zonas)))) . '}';
            
            // Verificar si ya existe configuración
            $check = $this->db->prepare("SELECT id FROM empresas_configuracion WHERE empresa_id = ?");
            $check->execute([$empresaId]);
            
            if ($check->fetch()) {
                // Actualizar existente
                $update = $this->db->prepare("
                    UPDATE empresas_configuracion 
                    SET zona_operacion = ?, actualizado_en = NOW() 
                    WHERE empresa_id = ?
                ");
                $update->execute([$zonaPostgres, $empresaId]);
            } else {
                // Crear nueva configuración
                $insert = $this->db->prepare("
                    INSERT INTO empresas_configuracion (empresa_id, zona_operacion, creado_en) 
                    VALUES (?, ?, NOW())
                ");
                $insert->execute([$empresaId, $zonaPostgres]);
            }
            
            error_log("Zona de operación inicializada para empresa $empresaId: " . implode(', ', $zonas));
            
        } catch (Exception $e) {
            error_log("Error inicializando zona de operación: " . $e->getMessage());
        }
    }
    
    /**
     * Create a new usuario record for empresa admin
     * @return array with id
     */
    public function createUsuario($userData) {
        $query = "INSERT INTO usuarios (
            uuid, nombre, apellido, email, telefono, hash_contrasena, 
            tipo_usuario, empresa_id, es_activo
        ) VALUES (?, ?, ?, ?, ?, ?, 'empresa', ?, 1)
        RETURNING id";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            $userData['uuid'],
            $userData['nombre'],
            $userData['apellido'],
            $userData['email'],
            $userData['telefono'],
            $userData['hash_contrasena'],
            $userData['empresa_id']
        ]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update empresa with creator user id
     */
    public function updateEmpresaCreador($empresaId, $userId) {
        $stmt = $this->db->prepare("UPDATE empresas_transporte SET creado_por = ? WHERE id = ?");
        $stmt->execute([$userId, $empresaId]);
    }
    
    /**
     * Check if email already exists in usuarios table
     */
    public function checkEmailExists($email) {
        $stmt = $this->db->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
    
    /**
     * Register a device for a user
     */
    public function registerDevice($userId, $deviceUuid) {
        if (empty($deviceUuid)) {
            return false;
        }
        
        $stmt = $this->db->prepare(
            'INSERT INTO user_devices (user_id, device_uuid, trusted) 
             VALUES (?, ?, 1) 
             ON CONFLICT (user_id, device_uuid) DO NOTHING'
        );
        $stmt->execute([$userId, trim($deviceUuid)]);
        return true;
    }
    
    /**
     * Log audit action
     */
    public function logAudit($adminId, $action, $tabla, $registroId, $detalles) {
        try {
            // Check if audit_logs table exists
            $checkTable = $this->db->query("SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_name = 'audit_logs'
            )");
            $exists = $checkTable->fetchColumn();
            
            if (!$exists) {
                return;
            }
            
            $query = "INSERT INTO audit_logs (admin_id, action, tabla_afectada, registro_id, detalles, ip_address) 
                      VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                $adminId,
                $action,
                $tabla,
                $registroId,
                json_encode($detalles),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Error al registrar auditoría: " . $e->getMessage());
        }
    }
    
    /**
     * Enable default vehicle types for a new empresa
     * Creates all vehicle types as active by default
     */
    public function enableDefaultVehicleTypes($empresaId, $userId = null) {
        try {
            // Verificar si existe la tabla normalizada
            $checkTable = $this->db->query("SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' AND table_name = 'empresa_tipos_vehiculo'
            )");
            $tableExists = $checkTable->fetchColumn();
            
            if ($tableExists) {
                // Obtener tipos seleccionados en el registro
                $stmt = $this->db->prepare("SELECT tipos_vehiculo FROM empresas_transporte WHERE id = ?");
                $stmt->execute([$empresaId]);
                $tiposRaw = $stmt->fetchColumn();

                if (empty($tiposRaw)) {
                     return 0;
                }

                $tiposSeleccionados = [];
                // Parsear array de PostgreSQL
                if (substr($tiposRaw, 0, 1) === '{') {
                     $tiposSeleccionados = str_getcsv(trim($tiposRaw, '{}'));
                } else {
                     // Intento de fallback
                     $tiposSeleccionados = explode(',', $tiposRaw);
                }
                
                // Limpiar comillas
                $tiposSeleccionados = array_map(function($t) { 
                    return trim($t, '" '); 
                }, $tiposSeleccionados);
                $tiposSeleccionados = array_filter($tiposSeleccionados);

                if (empty($tiposSeleccionados)) return 0;

                // Habilitar cada tipo seleccionado
                $insertStmt = $this->db->prepare("
                    INSERT INTO empresa_tipos_vehiculo 
                        (empresa_id, tipo_vehiculo_codigo, activo, fecha_activacion, activado_por)
                    VALUES (?, ?, true, NOW(), ?)
                    ON CONFLICT (empresa_id, tipo_vehiculo_codigo) 
                    DO UPDATE SET 
                        activo = true, 
                        fecha_activacion = NOW(),
                        activado_por = EXCLUDED.activado_por
                ");
                
                foreach ($tiposSeleccionados as $tipo) {
                    $insertStmt->execute([$empresaId, $tipo, $userId]);
                }
                
                error_log("Vehículos habilitados repo: " . implode(',', $tiposSeleccionados));
                return count($tiposSeleccionados);
            }
            
            return 0;
        } catch (Exception $e) {
            error_log("Error habilitando tipos de vehículo por defecto: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Begin database transaction
     */
    public function beginTransaction() {
        $this->db->beginTransaction();
    }
    
    /**
     * Commit database transaction
     */
    public function commit() {
        $this->db->commit();
    }
    
    /**
     * Rollback database transaction
     */
    /**
     * Rollback database transaction
     */
    public function rollback() {
        $this->db->rollBack();
    }

    /**
     * Get empresa by ID
     */
    public function getEmpresaById($id) {
        $query = "SELECT * FROM empresas_transporte WHERE id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Update empresa profile data
     */
    public function updateEmpresaProfile($id, $data) {
        $fields = [];
        $params = [];

        // Allowlist of updatable fields
        $allowedFields = [
            'nit', 'razon_social', 'direccion', 'municipio', 
            'departamento', 'telefono', 'telefono_secundario', 
            'email', 'descripcion'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $query = "UPDATE empresas_transporte SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($query);
        return $stmt->execute($params);
    }

    /**
     * Get company configuration settings
     */
    public function getCompanySettings($empresaId) {
        $query = "SELECT notificaciones_email, notificaciones_push 
                  FROM empresas_configuracion 
                  WHERE empresa_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$empresaId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If no settings exist yet, return defaults
        if (!$result) {
            return [
                'notificaciones_email' => true,
                'notificaciones_push' => true
            ];
        }
        
        return [
            'notificaciones_email' => $result['notificaciones_email'] === true || $result['notificaciones_email'] === 't',
            'notificaciones_push' => $result['notificaciones_push'] === true || $result['notificaciones_push'] === 't'
        ];
    }

    /**
     * Update company configuration settings
     */
    public function updateCompanySettings($empresaId, $data) {
        // First ensure record exists
        $check = $this->db->prepare("SELECT id FROM empresas_configuracion WHERE empresa_id = ?");
        $check->execute([$empresaId]);
        
        if (!$check->fetch()) {
            $init = $this->db->prepare("INSERT INTO empresas_configuracion (empresa_id) VALUES (?)");
            $init->execute([$empresaId]);
        }
        
        $fields = [];
        $params = [];
        
        if (isset($data['notificaciones_email'])) {
            $fields[] = "notificaciones_email = ?";
            // Handle boolean or string 'true'/'false'
            $val = $data['notificaciones_email'];
            $params[] = ($val === true || $val === 'true' || $val === 1 || $val === '1') ? 't' : 'f';
        }
        
        if (isset($data['notificaciones_push'])) {
            $fields[] = "notificaciones_push = ?";
            $val = $data['notificaciones_push'];
            $params[] = ($val === true || $val === 'true' || $val === 1 || $val === '1') ? 't' : 'f';
        }
        
        if (empty($fields)) {
            return true; // Nothing to update
        }
        
        $params[] = $empresaId;
        $query = "UPDATE empresas_configuracion SET " . implode(', ', $fields) . ", actualizado_en = NOW() WHERE empresa_id = ?";
        
        $stmt = $this->db->prepare($query);
        return $stmt->execute($params);
    }
}
