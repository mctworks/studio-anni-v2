<?php

declare(strict_types=1);

namespace App\Auth;

final class RateLimiter
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function tooMany(string $action, string $key, int $max, int $windowSeconds, ?int $now = null): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM rate_events WHERE action = ? AND key_hash = ? AND created_at > ?');
        $st->execute([$action, self::hash($key), gmdate('Y-m-d H:i:s', ($now ?? time()) - $windowSeconds)]);

        return (int) $st->fetchColumn() >= $max;
    }

    public function hit(string $action, string $key, ?int $now = null): void
    {
        $now ??= time();
        $this->pdo->prepare('INSERT INTO rate_events (action, key_hash, created_at) VALUES (?, ?, ?)')
            ->execute([$action, self::hash($key), gmdate('Y-m-d H:i:s', $now)]);
        if (random_int(1, 50) === 1) {
            $this->pdo->prepare('DELETE FROM rate_events WHERE created_at < ?')->execute([gmdate('Y-m-d H:i:s', $now - 86400)]);
        }
    }

    private static function hash(string $key): string
    {
        return hash('sha256', strtolower($key));
    }
}
