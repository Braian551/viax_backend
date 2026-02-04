<?php
/**
 * AuthService.php
 * 
 * Reusable service for authentication-related operations.
 * Designed to work with any user type (empresa, admin, conductor, cliente).
 * 
 * Clean Architecture: Service Layer
 */

class AuthService {
    
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Check if a user has a password set
     * Users registered via Google OAuth may not have one
     * 
     * @param int $userId
     * @return array ['has_password' => bool, 'auth_provider' => string|null]
     */
    public function checkPasswordStatus($userId) {
        $stmt = $this->db->prepare("
            SELECT hash_contrasena, 
                   CASE 
                       WHEN google_id IS NOT NULL THEN 'google'
                       ELSE 'email'
                   END as auth_provider
            FROM usuarios 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("Usuario no encontrado");
        }
        
        return [
            'has_password' => !empty($user['hash_contrasena']),
            'auth_provider' => $user['auth_provider']
        ];
    }
    
    /**
     * Change password for a user
     * 
     * @param int $userId
     * @param string|null $currentPassword - Required if user has existing password
     * @param string $newPassword
     * @return bool
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        // 1. Validate new password strength
        if (strlen($newPassword) < 8) {
            throw new Exception("La contraseña debe tener al menos 8 caracteres");
        }
        
        // 2. Get current user data
        $stmt = $this->db->prepare("SELECT hash_contrasena FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("Usuario no encontrado");
        }
        
        // 3. If user has existing password, verify current password
        if (!empty($user['hash_contrasena'])) {
            if (empty($currentPassword)) {
                throw new Exception("Debes proporcionar tu contraseña actual");
            }
            
            if (!password_verify($currentPassword, $user['hash_contrasena'])) {
                throw new Exception("La contraseña actual es incorrecta");
            }
        }
        
        // 4. Hash and update new password
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $updateStmt = $this->db->prepare("
            UPDATE usuarios 
            SET hash_contrasena = ?, fecha_actualizacion = NOW() 
            WHERE id = ?
        ");
        $success = $updateStmt->execute([$newHash, $userId]);
        
        if (!$success) {
            throw new Exception("Error al actualizar la contraseña");
        }
        
        return true;
    }
    
    /**
     * Set password for a user who doesn't have one or is resetting it
     * 
     * @param int $userId
     * @param string $newPassword
     * @return bool
     */
    public function setPassword($userId, $newPassword) {
        // Check if user exists
        $status = $this->checkPasswordStatus($userId);
        
        // REMOVED: Restriction on existing password to allow password recovery flow
        // if ($status['has_password']) {
        //    throw new Exception("El usuario ya tiene una contraseña. Usa changePassword para cambiarla.");
        // }
        
        // Validate password strength
        if (strlen($newPassword) < 8) {
            throw new Exception("La contraseña debe tener al menos 8 caracteres");
        }
        
        // Hash and set password
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $stmt = $this->db->prepare("
            UPDATE usuarios 
            SET hash_contrasena = ?, fecha_actualizacion = NOW() 
            WHERE id = ?
        ");
        
        return $stmt->execute([$hash, $userId]);
    }
    
    /**
     * Verify a password for a given user
     * 
     * @param int $userId
     * @param string $password
     * @return bool
     */
    public function verifyPassword($userId, $password) {
        $stmt = $this->db->prepare("SELECT hash_contrasena FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || empty($user['hash_contrasena'])) {
            return false;
        }
        
        return password_verify($password, $user['hash_contrasena']);
    }
}
