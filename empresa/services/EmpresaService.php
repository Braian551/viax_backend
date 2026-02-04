<?php
/**
 * EmpresaService.php
 * Handles business logic for empresa registration
 * Single Responsibility: Business Rules Coordination
 */

require_once __DIR__ . '/../validators/EmpresaValidator.php';
require_once __DIR__ . '/../repositories/EmpresaRepository.php';
require_once __DIR__ . '/../../utils/Mailer.php';

class EmpresaService {
    
    private $repository;
    private $validator;
    
    public function __construct($db) {
        $this->repository = new EmpresaRepository($db);
        $this->validator = new EmpresaValidator($db);
    }
    
    /**
     * Register a new empresa with admin user
     * @return array registration result
     */
    /**
     * Register a new empresa (Database Transaction part)
     * @return array registration result + data for notifications
     */
    public function processRegistration($input) {
        // 1. Validate all inputs
        $email = $this->validator->validateAll($input);
        
        // 2. Process vehicle types
        $tiposVehiculo = $this->processVehicleTypes($input['tipos_vehiculo'] ?? []);
        
        // 3. Handle logo upload
        $logoUrl = $this->uploadLogo();
        
        // 4. Process representative name
        $representante = $this->processRepresentativeName($input);
        
        // 5. Start transaction and create records
        $this->repository->beginTransaction();
        
        try {
            // Create empresa
            $empresaData = $this->prepareEmpresaData($input, $email, $tiposVehiculo, $logoUrl, $representante['nombre_completo']);
            $empresaResult = $this->repository->createEmpresa($empresaData);
            $empresaId = $empresaResult['id'];
            
            // Create admin user
            $usuarioData = $this->prepareUsuarioData($input, $email, $representante, $empresaId);
            $usuarioResult = $this->repository->createUsuario($usuarioData);
            $userId = $usuarioResult['id'];
            
            // Update empresa with creator
            $this->repository->updateEmpresaCreador($empresaId, $userId);
            
            // Enable default vehicle types (moto, auto, motocarro)
            $tiposHabilitados = $this->repository->enableDefaultVehicleTypes($empresaId, $userId);
            if ($tiposHabilitados > 0) {
                error_log("Habilitados $tiposHabilitados tipos de vehículo para empresa $empresaId");
            }
            
            // Create secondary user for representative if email is different
            $representanteEmail = $input['representante_email'] ?? null;
            if ($representanteEmail && strtolower(trim($representanteEmail)) !== strtolower(trim($email))) {
                // Check if this email already exists
                $existingCheck = $this->repository->checkEmailExists($representanteEmail);
                if (!$existingCheck) {
                    // Create user for representative personal email
                    $repUsuarioData = [
                        'uuid' => uniqid('empresa_rep_', true),
                        'nombre' => $representante['nombre'],
                        'apellido' => $representante['apellido'],
                        'email' => $representanteEmail,
                        'telefono' => $input['representante_telefono'] ?? null,
                        'hash_contrasena' => password_hash($input['password'], PASSWORD_DEFAULT),
                        'empresa_id' => $empresaId
                    ];
                    $this->repository->createUsuario($repUsuarioData);
                    error_log("Created secondary empresa user for: $representanteEmail");
                }
            }
            
            // Register device if provided
            $deviceRegistered = $this->repository->registerDevice($userId, $input['device_uuid'] ?? '');
            
            // Log audit
            $this->repository->logAudit(null, 'empresa_registrada_publico', 'empresas_transporte', $empresaId, [
                'nombre' => $input['nombre_empresa'],
                'email' => $email,
                'nit' => $input['nit'] ?? null
            ]);
            
            // Commit transaction
            $this->repository->commit();
            
            // Return success data needed for response AND notifications
            return [
                'success' => true,
                'message' => 'Empresa registrada exitosamente. Tu solicitud está pendiente de aprobación.',
                'data' => [
                    'empresa_id' => $empresaId,
                    'user' => [
                        'id' => $userId,
                        'uuid' => $usuarioData['uuid'],
                        'nombre' => $representante['nombre'],
                        'apellido' => $representante['apellido'],
                        'email' => $email,
                        'telefono' => trim($input['telefono']),
                        'tipo_usuario' => 'empresa',
                        'empresa_id' => $empresaId
                    ],
                    'estado' => 'pendiente',
                    'device_registered' => $deviceRegistered
                ],
                // Context data for background notifications
                'notification_context' => [
                    'email' => $email, 
                    'input' => $input, 
                    'representante_nombre' => $representante['nombre_completo'], 
                    'logo_url' => $logoUrl,
                    'empresa_id' => $empresaId,
                    'nombre_empresa' => $input['nombre_empresa']
                ]
            ];
            
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /**
     * Send notifications for a registration (Emails)
     * Should be called AFTER response is sent to client
     */
    /**
     * Send notifications for a registration (Emails)
     * Should be called AFTER response is sent to client
     */
    public function sendNotifications($context) {
        $email = $context['email']; // Company/Main Email
        $input = $context['input'];
        $representante = $context['representante_nombre'];
        $logoUrl = $context['logo_url'];
        $empresaId = $context['empresa_id'];
        $nombreEmpresa = $context['nombre_empresa'];

        // --- Generate Registration PDF ---
        $pdfPath = null;
        try {
            require_once __DIR__ . '/../../utils/PdfGenerator.php';
            $pdfGen = new PdfGenerator();
            
            // Prepare data for PDF (merge input with computed fields)
            $pdfData = $input;
            $pdfData['logo_url'] = $logoUrl;
            $pdfData['representante_nombre'] = $representante;
            $pdfData['created_at'] = date('d/m/Y'); // fallback
            
            $pdfPath = $pdfGen->generateRegistrationPdf($pdfData);
            
            // Attach PDF path to input so Mailer can pick it up
            if ($pdfPath && file_exists($pdfPath)) {
                $input['_pdf_path'] = $pdfPath;
            }
            
        } catch (Exception $e) {
            error_log("PDF Generation failed in sendNotifications: " . $e->getMessage());
        }

        // Determine email type based on status
        $status = $input['estado'] ?? 'pendiente';
        $emailType = ($status === 'activo') ? 'approved' : 'welcome';

        // 1. Send to Company Email (Main)
        $this->sendEmailSequence($email, $input, $representante, $logoUrl, $emailType);
        
        // 2. Send to Personal Email (if provided and different)
        $personalEmail = $input['representante_email'] ?? null;
        if ($personalEmail && strtolower(trim($personalEmail)) !== strtolower(trim($email))) {
            if (filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
                $this->sendEmailSequence($personalEmail, $input, $representante, $logoUrl, $emailType);
            }
        }
        
        // Cleanup PDF
        if ($pdfPath && file_exists($pdfPath)) {
            @unlink($pdfPath);
        }
        
        // Notify admins if it was a public registration (pending)
        if ($status === 'pendiente') {
            $this->notifyAdmins($empresaId, $nombreEmpresa, $email, $representante);
        }
    }
    
    /**
     * Send notifications for manual approval (Admin Button)
     */
    public function sendApprovalNotifications($empresaData) {
        $email = $empresaData['email'];
        $representante = $empresaData['representante_nombre'];
        $logoUrl = $empresaData['logo_url'];
        
        // Map DB fields to Input format expected by Mailer/Service
        $simulatedInput = [
            'nombre_empresa' => $empresaData['nombre'], // DB: nombre -> Input: nombre_empresa
            'nit' => $empresaData['nit'],
            'razon_social' => $empresaData['razon_social'],
            'email' => $empresaData['email'],
            'telefono' => $empresaData['telefono'],
            'direccion' => $empresaData['direccion'],
            'municipio' => $empresaData['municipio'],
            'departamento' => $empresaData['departamento'],
            'tipos_vehiculo' => $empresaData['tipos_vehiculo'], // array or string from DB
            'representante_nombre' => $representante,
            // No PW/PDF for simple approval usually
        ];

        // 1. Company Email
        $this->sendEmailSequence($email, $simulatedInput, $representante, $logoUrl, 'approved');

        // 2. Personal Email (if different)
        $personalEmail = $empresaData['representante_email'] ?? null;
        if ($personalEmail && strtolower(trim($personalEmail)) !== strtolower(trim($email))) {
            if (filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
                $this->sendEmailSequence($personalEmail, $simulatedInput, $representante, $logoUrl, 'approved');
            }
        }
    }

    /**
     * Helper to send the correct email type
     */
    private function sendEmailSequence($email, $inputData, $representante, $logoUrl, $type) {
        try {
            // Prepare data for Mailer
            $mailData = [
                'nombre_empresa' => $inputData['nombre_empresa'],
                'nit' => $inputData['nit'] ?? null,
                'razon_social' => $inputData['razon_social'] ?? null,
                'email' => $inputData['email'] ?? $email, // Fallback
                'telefono' => trim($inputData['telefono']),
                'direccion' => $inputData['direccion'] ?? null,
                'municipio' => $inputData['municipio'] ?? null,
                'departamento' => $inputData['departamento'] ?? null,
                'tipos_vehiculo' => $inputData['tipos_vehiculo'] ?? [],
                'representante_nombre' => $representante,
                'logo_url' => $logoUrl,
                '_pdf_path' => $inputData['_pdf_path'] ?? null,
            ];

            if ($type === 'approved') {
                Mailer::sendCompanyApprovedEmail($email, $representante, $mailData);
            } else {
                Mailer::sendCompanyWelcomeEmail($email, $representante, $mailData);
            }
        } catch (Exception $e) {
            error_log("Error sending $type email to $email: " . $e->getMessage());
        }
    }
    
    /**
     * Notify admins about new empresa registration
     */
    /**
     * Notify admins about new empresa registration
     */
    private function notifyAdmins($empresaId, $nombreEmpresa, $email, $representante) {
        // Implementation would go here - keeping it simple for now
        error_log("New empresa registered: $nombreEmpresa (ID: $empresaId)");
    }
    
    // --- Helper Methods ---
    
    private function processRepresentativeName($input) {
        $nombre = $input['representante_nombre'] ?? '';
        $apellido = $input['representante_apellido'] ?? '';
        
        // If legacy single field provided, try to split if apellido is empty
        if (empty($apellido) && strpos($nombre, ' ') !== false) {
            $parts = explode(' ', $nombre, 2);
            $nombre = $parts[0];
            $apellido = $parts[1] ?? '';
        }
        
        return [
            'nombre' => trim($nombre),
            'apellido' => trim($apellido),
            'nombre_completo' => trim("$nombre $apellido")
        ];
    }
    
    private function processVehicleTypes($types) {
        if (is_string($types)) {
            $decoded = json_decode($types, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $types = $decoded;
            } else {
                $types = explode(',', $types);
            }
        }
        
        if (!is_array($types)) {
            return [];
        }
        
        // PostgreSQL array format
        $pgArray = '{' . implode(',', array_map(function($t) {
            return '"' . trim($t) . '"'; 
        }, $types)) . '}';
        
        return $pgArray;
    }
    
    private function uploadLogo() {
        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        
        try {
            require_once __DIR__ . '/../../config/R2Service.php';
            $r2 = new R2Service();
            
            $file = $_FILES['logo'];
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'empresas/logos/' . uniqid('logo_') . '.' . $extension;
            
            // Upload to R2 and return the key
            $r2Key = $r2->uploadFile($file['tmp_name'], $filename, $file['type']);
            
            error_log("Logo uploaded to R2: $r2Key");
            return $r2Key;
            
        } catch (Exception $e) {
            error_log("Error uploading logo to R2: " . $e->getMessage());
            return null;
        }
    }
    
    private function prepareEmpresaData($input, $email, $tiposVehiculo, $logoUrl, $representanteNombreCompleto) {
        return [
            'nombre' => $input['nombre_empresa'],
            'nit' => $input['nit'] ?? null,
            'razon_social' => $input['razon_social'] ?? null,
            'email' => $email,
            'telefono' => $input['telefono'],
            'telefono_secundario' => $input['telefono_secundario'] ?? null,
            'direccion' => $input['direccion'],
            'municipio' => $input['municipio'],
            'departamento' => $input['departamento'],
            'representante_nombre' => $representanteNombreCompleto,
            'representante_telefono' => $input['representante_telefono'] ?? null,
            'representante_email' => $input['representante_email'] ?? null,
            'tipos_vehiculo' => $tiposVehiculo,
            'logo_url' => $logoUrl,
            'descripcion' => $input['descripcion'] ?? null,
            'estado' => $input['estado'] ?? 'pendiente',
            'notas_admin' => $input['notas_admin'] ?? null
        ];
    }
    
    private function prepareUsuarioData($input, $email, $representante, $empresaId) {
        return [
            'uuid' => uniqid('empresa_', true),
            'nombre' => $representante['nombre'],
            'apellido' => $representante['apellido'],
            'email' => $email,
            'telefono' => $input['telefono'],
            'hash_contrasena' => password_hash($input['password'], PASSWORD_DEFAULT),
            'tipo_usuario' => 'empresa',
            'empresa_id' => $empresaId
        ];
    }

    /**
     * Get company profile details using clean architecture
     */
    public function getCompanyProfile($empresaId) {
        $empresa = $this->repository->getEmpresaById($empresaId);
        if (!$empresa) {
            throw new Exception("Empresa no encontrada");
        }
        
        // Remove internal fields
        unset($empresa['creado_por'], $empresa['verificado_por'], $empresa['creado_en'], $empresa['actualizado_en']);
        
        // Convert logo URL to proxy URL
        if (!empty($empresa['logo_url'])) {
            $empresa['logo_url'] = $this->convertLogoUrl($empresa['logo_url']);
        }
        
        return $empresa;
    }
    
    /**
     * Convert logo URL to accessible proxy URL
     */
    private function convertLogoUrl($logoUrl) {
        if (empty($logoUrl)) {
            return null;
        }
        
        // If already a proxy URL, return as is
        if (strpos($logoUrl, 'r2_proxy.php') !== false) {
            return $logoUrl;
        }
        
        // If direct R2 URL, extract the key
        if (strpos($logoUrl, 'r2.dev/') !== false) {
            $parts = explode('r2.dev/', $logoUrl);
            $logoUrl = end($parts);
        }
        
        // If already a complete URL from another domain, return as is
        if (strpos($logoUrl, 'http://') === 0 || strpos($logoUrl, 'https://') === 0) {
            return $logoUrl;
        }
        
        // Convert relative path to proxy URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "$protocol://$host/backend/r2_proxy.php?key=" . urlencode($logoUrl);
    }

    /**
     * Update company profile details
     */
    public function updateCompanyProfile($empresaId, $data) {
        $empresa = $this->repository->getEmpresaById($empresaId);
        if (!$empresa) {
            throw new Exception("Empresa no encontrada");
        }

        // Validate basic fields (email format, required fields if completely clearing them)
        if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email inválido");
        }
        
        // Handle logo upload if present
        $newLogoUrl = $this->uploadLogo();
        if ($newLogoUrl) {
            $data['logo_url'] = $newLogoUrl;
        }

        // Update database
        $success = $this->repository->updateEmpresaProfile($empresaId, $data);
        
        if ($success) {
            return $this->getCompanyProfile($empresaId);
        }
        
        
        throw new Exception("No se pudo actualizar el perfil");
    }

    /**
     * Get company notification settings
     */
    public function getCompanySettings($empresaId) {
        return $this->repository->getCompanySettings($empresaId);
    }

    /**
     * Update company notification settings
     */
    public function updateCompanySettings($empresaId, $data) {
        $success = $this->repository->updateCompanySettings($empresaId, $data);
        if ($success) {
            return $this->getCompanySettings($empresaId);
        }
        throw new Exception("No se pudo actualizar la configuración");
    }
}
