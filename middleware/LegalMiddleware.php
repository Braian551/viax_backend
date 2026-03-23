<?php

require_once __DIR__ . '/../config/app.php';

class LegalMiddleware
{
    public static function checkLegalAcceptance(int $userId, string $role): void
    {
        error_log("[LegalMiddleware] Verificando acceso para user_id={$userId}, role={$role}");

        $currentVersion = self::getCurrentLegalVersion($role);
        $acceptedVersion = self::getUserAcceptedVersion($userId, $role);
        
        error_log("[LegalMiddleware] Versión requerida: " . ($currentVersion ?? 'N/A') . " | Versión usuario: " . ($acceptedVersion ?? 'N/A'));

        // Fail-safe: Si la DB colapsa y no hay versión configurada, no bloqueamos la app globalmente
        if ($currentVersion === null) {
            error_log("[LegalMiddleware] ACCESO PERMITIDO (Fail-safe, no current_version)");
            return;
        }

        if ($currentVersion !== $acceptedVersion) {
            error_log("[LegalMiddleware] BLOQUEADO. Las versiones no coinciden.");
            http_response_code(403);
            die(json_encode([
                'error' => 'LEGAL_VERSION_EXPIRED',
                'message' => 'Por favor acepte la versión más reciente de nuestras políticas.',
                'requiresUpdate' => true
            ]));
        }
        
        error_log("[LegalMiddleware] ACCESO CONCEDIDO.");
    }
    
    private static function getCurrentLegalVersion(string $role): ?string
    {
        try {
            if (class_exists('Cache')) {
                $redis = Cache::redis();
                if ($redis) {
                    $cached = $redis->get("legal_current_version:{$role}");
                    if ($cached) {
                        error_log("[LegalMiddleware] current_version obtenida desde REDIS.");
                        return $cached;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("[LegalMiddleware] Error Redis (current_version): " . $e->getMessage());
        }
        
        error_log("[LegalMiddleware] current_version obtenida desde POSTGRESQL (Fallback)");
        try {
            $database = new Database();
            $db = $database->getConnection();
            $stmt = $db->prepare("SELECT version FROM legal_documents WHERE role = ? AND is_active = true ORDER BY published_at DESC LIMIT 1");
            $stmt->execute([$role]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $version = $doc ? $doc['version'] : null;
            
            if (class_exists('Cache') && $version) {
                try { Cache::redis()?->setex("legal_current_version:{$role}", 300, $version); } catch (Throwable $e) {}
            }
            return $version;
        } catch (Throwable $e) {
            error_log("[LegalMiddleware] Error Database (current_version): " . $e->getMessage());
            return null;
        }
    }
    
    private static function getUserAcceptedVersion(int $userId, string $role): ?string
    {
        try {
            if (class_exists('Cache')) {
                $redis = Cache::redis();
                if ($redis) {
                    $cached = $redis->get("legal_accepted:{$role}:{$userId}");
                    if ($cached) {
                        error_log("[LegalMiddleware] accepted_version obtenida desde REDIS.");
                        return $cached;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("[LegalMiddleware] Error Redis (accepted_version): " . $e->getMessage());
        }
        
        error_log("[LegalMiddleware] accepted_version obtenida desde POSTGRESQL (Fallback)");
        try {
            $database = new Database();
            $db = $database->getConnection();
            $stmt = $db->prepare("
                SELECT accepted_version 
                FROM legal_acceptance_logs 
                WHERE user_id = ? AND role = ? 
                ORDER BY accepted_at DESC LIMIT 1
            ");
            $stmt->execute([$userId, $role]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $version = $row ? $row['accepted_version'] : null;
            
            if (class_exists('Cache') && $version) {
                try { Cache::redis()?->setex("legal_accepted:{$role}:{$userId}", 3600, $version); } catch (Throwable $e) {}
            }
            return $version;
        } catch (Throwable $e) {
            error_log("[LegalMiddleware] Error Database (accepted_version): " . $e->getMessage());
            return null;
        }
    }
}
