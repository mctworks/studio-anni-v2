<?php

declare(strict_types=1);

namespace App\Auth;

final class LoginThrottle
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly int $maxPerAccountAndIp = 5,
        private readonly int $maxPerIp = 30,
        private readonly int $windowSeconds = 900,
    ) {
    }

    // callers check this before verifying a password and must not record attempts while locked
    public function retryAfter(string $email, string $ip, ?int $now = null): int
    {
        $now ??= time();
        $packed = ClientIp::pack($ip);

        return max(
            $this->wait('identifier_hash = ? AND ip = ?', [self::identifier($email), $packed], $this->maxPerAccountAndIp, $now),
            $this->wait('ip = ?', [$packed], $this->maxPerIp, $now),
        );
    }

    public function recordFailure(string $email, string $ip, ?int $now = null): void
    {
        $now ??= time();
        $this->pdo->prepare('INSERT INTO login_attempts (identifier_hash, ip, success, attempted_at) VALUES (?, ?, 0, ?)')
            ->execute([self::identifier($email), ClientIp::pack($ip), gmdate('Y-m-d H:i:s', $now)]);
        if (random_int(1, 50) === 1) {
            $this->prune($now);
        }
    }

    public function recordSuccess(string $email, string $ip, ?int $now = null): void
    {
        $now ??= time();
        $id = self::identifier($email);
        $packed = ClientIp::pack($ip);
        $this->pdo->prepare('DELETE FROM login_attempts WHERE identifier_hash = ? AND ip = ? AND success = 0')->execute([$id, $packed]);
        $this->pdo->prepare('INSERT INTO login_attempts (identifier_hash, ip, success, attempted_at) VALUES (?, ?, 1, ?)')
            ->execute([$id, $packed, gmdate('Y-m-d H:i:s', $now)]);
    }

    public function prune(?int $now = null): int
    {
        $st = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
        $st->execute([gmdate('Y-m-d H:i:s', ($now ?? time()) - 86400)]);

        return $st->rowCount();
    }

    public static function identifier(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    private function wait(string $where, array $params, int $max, int $now): int
    {
        $st = $this->pdo->prepare(
            "SELECT attempted_at FROM login_attempts WHERE success = 0 AND attempted_at > ? AND {$where} ORDER BY attempted_at DESC LIMIT 1 OFFSET ?"
        );
        $i = 1;
        $st->bindValue($i++, gmdate('Y-m-d H:i:s', $now - $this->windowSeconds));
        foreach ($params as $p) {
            $st->bindValue($i++, $p);
        }
        $st->bindValue($i, $max - 1, \PDO::PARAM_INT);
        $st->execute();
        $at = $st->fetchColumn();

        return $at === false ? 0 : max(0, (int) strtotime($at . ' UTC') + $this->windowSeconds - $now);
    }
}
