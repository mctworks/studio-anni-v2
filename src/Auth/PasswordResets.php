<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config;
use App\Mail\Mailer;

final class PasswordResets
{
    private const TTL = 3600;

    public function __construct(private readonly \PDO $pdo, private readonly Mailer $mailer, private readonly Config $config)
    {
    }

    public function request(int $userId, string $email, string $ip, ?int $now = null): bool
    {
        $token = $this->issue($userId, $ip, $now);
        $link = rtrim($this->config->getRequired('APP_URL'), '/') . '/reset#token=' . $token;
        $sent = $this->mailer->send(
            $email,
            'Reset your Studio Anni password',
            "Use this link to choose a new Studio Anni password. It works once and expires in 1 hour.\n\n{$link}\n\nIf you did not ask for this, you can ignore this message; your password has not changed.",
        );
        if (! $sent) {
            $this->pdo->prepare('DELETE FROM password_resets WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        }

        return $sent;
    }

    public function issue(int $userId, string $ip, ?int $now = null): string
    {
        $now ??= time();
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $this->pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, hash('sha256', $token), gmdate('Y-m-d H:i:s', $now + self::TTL), ClientIp::pack($ip), gmdate('Y-m-d H:i:s', $now)]);

        return $token;
    }

    /** @return array{id: int, user_id: int, email: string}|null */
    public function find(string $token, ?int $now = null): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $st = $this->pdo->prepare(
            'SELECT r.id, r.user_id, u.email FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ? AND u.role = \'customer\' AND u.status = \'active\''
        );
        $st->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s', $now ?? time())]);
        $row = $st->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'email' => (string) $row['email']] : null;
    }

    public function consume(int $id, ?int $now = null): bool
    {
        $st = $this->pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $st->execute([gmdate('Y-m-d H:i:s', $now ?? time()), $id]);

        return $st->rowCount() === 1;
    }
}
