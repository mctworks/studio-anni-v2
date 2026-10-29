<?php

declare(strict_types=1);

namespace App\Auth;

final class AuditLog
{
    private const SECRET_KEY = '/pass|secret|token|code|key|hash/i';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function record(
        string $action,
        ?int $userId = null,
        ?string $targetType = null,
        ?string $targetId = null,
        array $details = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        try {
            $this->pdo->prepare(
                'INSERT INTO audit_log (created_at, user_id, action, target_type, target_id, ip, user_agent, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                gmdate('Y-m-d H:i:s'),
                $userId,
                substr($action, 0, 64),
                $targetType !== null ? substr($targetType, 0, 32) : null,
                $targetId !== null ? substr($targetId, 0, 64) : null,
                $ip !== null ? ClientIp::pack($ip) : null,
                $userAgent !== null ? substr($userAgent, 0, 255) : null,
                $details === [] ? null : json_encode(self::scrub($details), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            error_log('Audit log write failed: ' . $e::class);
        }
    }

    public function pruneOlderThan(int $days = 365): int
    {
        $st = $this->pdo->prepare('DELETE FROM audit_log WHERE created_at < ?');
        $st->execute([gmdate('Y-m-d H:i:s', time() - $days * 86400)]);

        return $st->rowCount();
    }

    private static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }
}
