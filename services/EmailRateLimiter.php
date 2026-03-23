<?php

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/SecurityLogger.php';

class EmailRateLimiter {
    private PDO $db;
    private SecurityLogger $logger;

    private array $limits = [
        'registration' => ['ip' => 15, 'user' => 5],
        'password_reset' => ['ip' => 12, 'user' => 5],
        'resend_verification' => ['ip' => 12, 'user' => 6],
        'account_deletion' => ['ip' => 8, 'user' => 4],
    ];

    public function __construct(PDO $db, SecurityLogger $logger) {
        $this->db = $db;
        $this->logger = $logger;
        $this->ensureTables();
    }

    public function assertAllowed(string $action, string $ipAddress, string $userIdentifier): void {
        $ipHash = $this->hashValue($ipAddress);
        $userHash = $this->hashValue(strtolower(trim($userIdentifier)));

        $this->assertIpNotBlocked($ipHash, $action);

        $ipAttempts = $this->incrementCounter('ip', $action, $ipHash);
        $userAttempts = $this->incrementCounter('user', $action, $userHash);

        $ipLimit = $this->getLimit($action, 'ip');
        $userLimit = $this->getLimit($action, 'user');

        if ($ipAttempts > $ipLimit) {
            $this->blockIp($ipHash, $action, $ipAttempts);
            throw new RuntimeException('Demasiados intentos desde tu red. Intenta más tarde.');
        }

        if ($userAttempts > $userLimit) {
            $this->logger->warning('Email user rate-limit exceeded', [
                'action' => $action,
                'user_hash' => $userHash,
                'attempts' => $userAttempts,
            ]);
            throw new RuntimeException('Has superado el límite de solicitudes por hora. Intenta más tarde.');
        }
    }

    private function ensureTables(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS email_rate_limits (
            scope_type VARCHAR(16) NOT NULL,
            scope_hash VARCHAR(64) NOT NULL,
            action VARCHAR(64) NOT NULL,
            window_start TIMESTAMP NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            PRIMARY KEY (scope_type, scope_hash, action, window_start)
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS email_ip_blocks (
            ip_hash VARCHAR(64) PRIMARY KEY,
            blocked_until TIMESTAMP NOT NULL,
            reason VARCHAR(128) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");
    }

    private function assertIpNotBlocked(string $ipHash, string $action): void {
        $stmt = $this->db->prepare("SELECT blocked_until FROM email_ip_blocks WHERE ip_hash = ? LIMIT 1");
        $stmt->execute([$ipHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && strtotime($row['blocked_until']) > time()) {
            $this->logger->warning('Blocked IP attempted to send email', [
                'action' => $action,
                'ip_hash' => $ipHash,
                'blocked_until' => $row['blocked_until'],
            ]);
            throw new RuntimeException('Tu red está temporalmente bloqueada por exceso de solicitudes.');
        }
    }

    private function incrementCounter(string $scopeType, string $action, string $scopeHash): int {
        $stmt = $this->db->prepare("INSERT INTO email_rate_limits (scope_type, scope_hash, action, window_start, attempts)
            VALUES (?, ?, ?, DATE_TRUNC('hour', NOW()), 1)
            ON CONFLICT (scope_type, scope_hash, action, window_start)
            DO UPDATE SET attempts = email_rate_limits.attempts + 1, updated_at = NOW()
            RETURNING attempts");

        $stmt->execute([$scopeType, $scopeHash, $action]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['attempts'] ?? 0);
    }

    private function blockIp(string $ipHash, string $action, int $attempts): void {
        $blockMinutes = (int) env_value('RATE_LIMIT_BLOCK_MINUTES', 120);
        $reason = sprintf('%s abuse detected (%d attempts)', $action, $attempts);

        $stmt = $this->db->prepare("INSERT INTO email_ip_blocks (ip_hash, blocked_until, reason)
            VALUES (:ip_hash, NOW() + (:minutes || ' minutes')::interval, :reason)
            ON CONFLICT (ip_hash)
            DO UPDATE SET blocked_until = EXCLUDED.blocked_until, reason = EXCLUDED.reason, updated_at = NOW()");

        $stmt->execute([
            'ip_hash' => $ipHash,
            'minutes' => $blockMinutes,
            'reason' => $reason,
        ]);

        $this->logger->warning('IP blocked due to email abuse', [
            'ip_hash' => $ipHash,
            'action' => $action,
            'attempts' => $attempts,
            'block_minutes' => $blockMinutes,
        ]);
    }

    private function getLimit(string $action, string $scope): int {
        $default = $this->limits[$action][$scope] ?? ($scope === 'ip' ? 10 : 4);

        if ($action === 'account_deletion') {
            $envKey = $scope === 'ip'
                ? 'RATE_LIMIT_ACCOUNT_DELETION_IP_PER_HOUR'
                : 'RATE_LIMIT_ACCOUNT_DELETION_USER_PER_HOUR';
            return (int) env_value($envKey, $default);
        }

        $envKey = 'RATE_LIMIT_' . strtoupper($scope) . '_PER_HOUR';
        return (int) env_value($envKey, $default);
    }

    private function hashValue(string $value): string {
        $salt = env_value('RATE_LIMIT_SECRET', 'viax-rate-limiter');
        return hash('sha256', $salt . '|' . $value);
    }
}
