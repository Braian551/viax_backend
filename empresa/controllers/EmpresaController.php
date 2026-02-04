<?php
/**
 * EmpresaController.php
 * Handles HTTP requests for empresa operations
 * Single Responsibility: HTTP Layer
 */

require_once __DIR__ . '/../services/EmpresaService.php';

class EmpresaController {
    
    private $service;
    
    public function __construct($db) {
        $this->service = new EmpresaService($db);
    }
    
    /**
     * Handle incoming HTTP request
     */
    public function handleRequest($input) {
        $action = $input['action'] ?? 'register';
        
        switch ($action) {
            case 'register':
                return $this->register($input);
            case 'get_profile':
                return $this->getProfile($input);
            case 'update_profile':
                return $this->updateProfile($input);
            case 'get_settings':
                return $this->getSettings($input);
            case 'update_settings':
                return $this->updateSettings($input);
            default:
                http_response_code(400);
                return $this->jsonResponse(false, 'Acción no válida');
        }
    }
    
    /**
     * Get company settings
     */
    private function getSettings($input) {
        try {
            if (!isset($input['empresa_id'])) {
                throw new Exception("ID de empresa requerido");
            }

            $currentUserId = $input['current_user_id'] ?? null;
            
            $data = $this->service->getCompanySettings($input['empresa_id']);
            
            return $this->jsonResponse(true, 'Configuración obtenida', $data);
        } catch (Exception $e) {
            http_response_code(400);
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    /**
     * Update company settings
     */
    private function updateSettings($input) {
        try {
            if (!isset($input['empresa_id'])) {
                throw new Exception("ID de empresa requerido");
            }
            
            $data = $this->service->updateCompanySettings($input['empresa_id'], $input);
            
            return $this->jsonResponse(true, 'Configuración actualizada', $data);
        } catch (Exception $e) {
            http_response_code(400);
            return $this->jsonResponse(false, $e->getMessage());
        }
    }
    
    /**
     * Get company profile
     */
    private function getProfile($input) {
        try {
            if (!isset($input['empresa_id'])) {
                throw new Exception("ID de empresa requerido");
            }

            $currentUserId = $input['current_user_id'] ?? null;
            // Verify ownership logic would go here if not done in profile.php

            $data = $this->service->getCompanyProfile($input['empresa_id']);
            
            return $this->jsonResponse(true, 'Perfil obtenido exitosamente', $data);
        } catch (Exception $e) {
            http_response_code(400);
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    /**
     * Update company profile
     */
    private function updateProfile($input) {
        try {
            if (!isset($input['empresa_id'])) {
                throw new Exception("ID de empresa requerido");
            }
            
            $data = $this->service->updateCompanyProfile($input['empresa_id'], $input);
            
            return $this->jsonResponse(true, 'Perfil actualizado exitosamente', $data);
        } catch (Exception $e) {
            http_response_code(400);
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    /**
     * Handle empresa registration
     */
    private function register($input) {
        try {
            // 1. Process Database Registration (Fast)
            $result = $this->service->processRegistration($input);
            
            // 2. Send Response to User (Closes connection)
            http_response_code(200);
            $this->jsonResponse(
                $result['success'],
                $result['message'],
                $result['data'] ?? []
            );
            
            // 3. Send Notifications (Slow - running in background)
            // This happens AFTER the user gets the response
            if (isset($result['notification_context'])) {
                $this->service->sendNotifications($result['notification_context']);
            }
            
            exit;
            
        } catch (Exception $e) {
            error_log("Empresa registration error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            http_response_code(500);
            // Can't use $this->jsonResponse here if headers already sent, but let's try
            return $this->jsonResponse(
                false,
                $e->getMessage(),
                [
                    'debug_error' => $e->getMessage(),
                    'debug_line' => $e->getLine(),
                    'debug_file' => basename($e->getFile())
                ]
            );
        }
    }
    
    /**
     * Format JSON response
     */
    /**
     * Format JSON response and close connection immediately
     * to allow background processing (like sending emails)
     */
    private function jsonResponse($success, $message, $data = []) {
        $response = [
            'success' => $success,
            'message' => $message
        ];
        
        if (!empty($data)) {
            $response['data'] = $data;
        }
        
        $json = json_encode($response);
        $length = strlen($json);
        
        // Prevent any output buffering from interfering
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Start fresh buffer
        ob_start();
        
        // Output the JSON
        echo $json;
        
        // Set headers to close connection
        header('Content-Type: application/json');
        header('Content-Length: ' . $length);
        header('Connection: close');
        
        // Flush the buffer to the client
        ob_end_flush();
        
        // Flush system buffers
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
        
        // Close session to release lock
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        
        // For FastCGI (nginx, some Apache configs)
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        // Continue running script
        ignore_user_abort(true);
        set_time_limit(120);
    }
}
