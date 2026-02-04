<?php
/**
 * EmpresaValidator.php
 * Handles all input validation for empresa registration
 * Single Responsibility: Input Validation
 */

class EmpresaValidator {
    
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Validate all required fields are present and not empty
     * @throws Exception if validation fails
     */
    public function validateRequiredFields($input) {
        $requiredFields = [
            'nombre_empresa' => 'El nombre de la empresa es requerido',
            'email' => 'El email es requerido',
            'password' => 'La contraseña es requerida',
            'telefono' => 'El teléfono es requerido',
            'representante_nombre' => 'El nombre del representante es requerido',
        ];
        
        foreach ($requiredFields as $field => $message) {
            if (empty($input[$field])) {
                throw new Exception($message);
            }
        }
    }
    
    /**
     * Validate email format
     * @throws Exception if email is invalid
     */
    public function validateEmail($email) {
        $validEmail = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
        if (!$validEmail) {
            throw new Exception('Email inválido');
        }
        return $validEmail;
    }
    
    /**
     * Validate password strength
     * @throws Exception if password is too weak
     */
    public function validatePassword($password) {
        if (strlen($password) < 6) {
            throw new Exception('La contraseña debe tener al menos 6 caracteres');
        }
    }
    
    /**
     * Check if email is already registered
     * @throws Exception if email exists
     */
    public function checkEmailUniqueness($email) {
        $stmt = $this->db->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Este email ya está registrado');
        }
    }
    
    /**
     * Check if NIT is already registered
     * @throws Exception if NIT exists
     */
    public function checkNitUniqueness($nit) {
        if (empty($nit)) {
            return; // NIT is optional
        }
        
        $stmt = $this->db->prepare("SELECT id FROM empresas_transporte WHERE nit = ?");
        $stmt->execute([$nit]);
        if ($stmt->fetch()) {
            throw new Exception('Ya existe una empresa con este NIT');
        }
    }
    
    /**
     * Check if phone is already registered
     * @throws Exception if phone exists
     */
    public function checkTelefonoUniqueness($telefono, $label = 'número de teléfono') {
        if (empty($telefono)) {
            return; 
        }
        
        $stmt = $this->db->prepare("SELECT id FROM usuarios WHERE telefono = ?");
        $stmt->execute([trim($telefono)]);
        if ($stmt->fetch()) {
            throw new Exception("El $label ya está registrado en el sistema");
        }
    }
    
    /**
     * Validate all inputs at once
     * @return array validated data
     * @throws Exception if any validation fails
     */
    public function validateAll($input) {
        $this->validateRequiredFields($input);
        
        $email = $this->validateEmail($input['email']);
        $this->validatePassword($input['password']);
        
        $this->checkEmailUniqueness($email);
        $this->checkNitUniqueness($input['nit'] ?? null);
        
        // Validate phones with specific context
        $this->checkTelefonoUniqueness($input['telefono'] ?? null, 'teléfono principal');
        
        if (!empty($input['telefono_secundario'])) {
            $this->checkTelefonoUniqueness($input['telefono_secundario'], 'teléfono secundario');
        }
        
        if (!empty($input['representante_telefono']) && 
            ($input['representante_telefono'] !== ($input['telefono'] ?? ''))) {
            $this->checkTelefonoUniqueness($input['representante_telefono'], 'teléfono del representante');
        }
        
        return $email;
    }
}
