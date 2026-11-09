<?php

declare(strict_types=1);

namespace App\Auth;

final class TrustedDevices
{
    public const DAYS = 30;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function issue(int $userId, string $userAgent, ?int $now = null): string
    {
        $now ??= time();
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO trusted_devices (user_id, token_hash, user_agent, expires_at, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, hash('sha256', $token), substr($userAgent, 0, 255), gmdate('Y-m-d H:i:s', $now + self::DAYS * 86400), gmdate('Y-m-d H:i:s', $now)]);
        if (random_int(1, 50) === 1) {
            $this->pdo->prepare('DELETE FROM trusted_devices WHERE expires_at < ?')->execute([gmdate('Y-m-d H:i:s', $now)]);
        }

        return $token;
    }

    public function valid(int $userId, ?string $token, ?int $now = null): bool
    {
        if ($token === null || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }
        $now ??= time();
        $st = $this->pdo->prepare('SELECT id FROM trusted_devices WHERE token_hash = ? AND user_id = ? AND expires_at > ?');
        $st->execute([hash('sha256', $token), $userId, gmdate('Y-m-d H:i:s', $now)]);
        $id = $st->fetchColumn();
        if ($id === false) {
            return false;
        }
        $this->pdo->prepare('UPDATE trusted_devices SET last_used_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $now), (int) $id]);

        return true;
    }

    public function revokeAll(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([$userId]);
    }
}
