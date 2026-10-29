<?php

declare(strict_types=1);

namespace App\Auth;

final class DbSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private ?int $userId = null;
    private readonly \Closure $clock;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly int $idleSeconds,
        private readonly int $absoluteSeconds,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function revokeUser(int $userId, ?string $keepSessionId = null): void
    {
        if ($keepSessionId === null) {
            $this->pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);

            return;
        }
        $this->pdo->prepare('DELETE FROM sessions WHERE user_id = ? AND id_hash <> ?')->execute([$userId, self::hash($keepSessionId)]);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = $this->row($id);
        if ($row === null) {
            return '';
        }
        if ($this->expired($row)) {
            $this->destroy($id);

            return '';
        }
        $this->userId = $row['user_id'] !== null ? (int) $row['user_id'] : null;

        return (string) $row['data'];
    }

    public function write(string $id, string $data): bool
    {
        $now = gmdate('Y-m-d H:i:s', ($this->clock)());
        $update = $this->pdo->prepare('UPDATE sessions SET data = ?, user_id = ?, last_activity = ? WHERE id_hash = ?');
        $update->execute([$data, $this->userId, $now, self::hash($id)]);
        if ($update->rowCount() > 0) {
            return true;
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO sessions (id_hash, user_id, data, ip, user_agent, created_at, last_activity) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                self::hash($id),
                $this->userId,
                $data,
                ClientIp::pack((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                $now,
                $now,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE id_hash = ?')->execute([self::hash($id)]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $now = ($this->clock)();
        $st = $this->pdo->prepare('DELETE FROM sessions WHERE last_activity < ? OR created_at < ?');
        $st->execute([gmdate('Y-m-d H:i:s', $now - $this->idleSeconds), gmdate('Y-m-d H:i:s', $now - $this->absoluteSeconds)]);

        return $st->rowCount();
    }

    public function validateId(string $id): bool
    {
        $row = $this->row($id);
        if ($row === null) {
            return false;
        }
        if ($this->expired($row)) {
            $this->destroy($id);

            return false;
        }

        return true;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->pdo->prepare('UPDATE sessions SET last_activity = ? WHERE id_hash = ?')
            ->execute([gmdate('Y-m-d H:i:s', ($this->clock)()), self::hash($id)]);

        return true;
    }

    private static function hash(string $id): string
    {
        return hash('sha256', $id);
    }

    private function row(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT user_id, data, created_at, last_activity FROM sessions WHERE id_hash = ?');
        $st->execute([self::hash($id)]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    private function expired(array $row): bool
    {
        $now = ($this->clock)();

        return $now - (int) strtotime($row['last_activity'] . ' UTC') > $this->idleSeconds
            || $now - (int) strtotime($row['created_at'] . ' UTC') > $this->absoluteSeconds;
    }
}
