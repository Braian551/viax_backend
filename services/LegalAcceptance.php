<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';

final class LegalAcceptance
{
    public static function role(mixed $role): ?string
    {
        return match ($role) {
            'admin', 'administrador' => 'administrador',
            'servidor', 'soporte', 'soporte_tecnico' => 'soporte_tecnico',
            'cliente', 'conductor', 'empresa' => $role,
            default => null,
        };
    }

    public static function current(PDO $db, string $role): array
    {
        $query = $db->prepare(
            'SELECT version, content_hash FROM legal_documents
             WHERE role = ? AND is_active = true ORDER BY published_at DESC, id DESC LIMIT 1'
        );
        $query->execute([$role]);
        $document = $query->fetch(PDO::FETCH_ASSOC);
        if ($document) {
            if (!preg_match('/^[a-f0-9]{64}$/Di', $document['content_hash'])) {
                throw new RuntimeException('Invalid published legal content hash');
            }
            return $document + ['enforced' => true];
        }

        $path = env_value('LEGAL_CONTENT_PATH', '/srv/viax/web/current/legal_content.json');
        $raw = is_readable($path) ? file_get_contents($path) : false;
        $catalogue = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        $content = $catalogue[$role === 'soporte_tecnico' ? 'servidor' : $role] ?? null;
        if (!isset($content['terms']['sections'], $content['privacy']['sections'])
            || !$content['terms']['sections'] || !$content['privacy']['sections']) {
            throw new RuntimeException('Published legal content unavailable');
        }
        $hash = hash('sha256', json_encode(
            ['terms' => $content['terms'], 'privacy' => $content['privacy']],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        return ['version' => 'web-' . substr($hash, 0, 16), 'content_hash' => $hash, 'enforced' => false];
    }

    public static function status(PDO $db, int $userId, string $role, array $document): array
    {
        $query = $db->prepare(
            "SELECT accepted_version, document_hash FROM legal_acceptance_logs
             WHERE user_id = ? AND role = ? AND device_id <> 'system_migration'
             ORDER BY accepted_at DESC, id DESC LIMIT 1"
        );
        $query->execute([$userId, $role]);
        $last = $query->fetch(PDO::FETCH_ASSOC);
        return [
            'accepted_current_version' => $last && $last['accepted_version'] === $document['version']
                && hash_equals($document['content_hash'], $last['document_hash']),
            'last_accepted_version' => $last['accepted_version'] ?? null,
            'has_any_acceptance' => (bool)$last,
        ];
    }

    public static function record(PDO $db, int $userId, string $role, string $version, string $deviceId): bool
    {
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))');
            $lock->execute(["viax:legal:{$role}:{$userId}"]);
            $document = self::current($db, $role);
            if ($version !== $document['version']) {
                $db->rollBack();
                return false;
            }
            if (!self::status($db, $userId, $role, $document)['accepted_current_version']) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $timestamp = time();
                $signature = hash('sha256', json_encode([
                    $userId, $role, $deviceId, $ip, $version, $document['content_hash'], $timestamp,
                ], JSON_THROW_ON_ERROR));
                $query = $db->prepare(
                    'INSERT INTO legal_acceptance_logs
                     (user_id, role, accepted_version, document_hash, ip_address, device_id, acceptance_hash, accepted_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, to_timestamp(?))'
                );
                $query->execute([$userId, $role, $version, $document['content_hash'], $ip, $deviceId, $signature, $timestamp]);
            }
            $db->commit();
            return true;
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}
