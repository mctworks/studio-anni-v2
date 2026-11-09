<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config;

final class EmailCodes
{
    private const TTL = 600;
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly \PDO $pdo, private readonly Config $config)
    {
    }

    public function issue(int $userId, string $purpose, ?int $now = null): string
    {
        $now ??= time();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->pdo->prepare('DELETE FROM email_codes WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$userId, $purpose]);
        $this->pdo->prepare('INSERT INTO email_codes (user_id, purpose, code_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $purpose, $this->mac($code, $userId, $purpose), gmdate('Y-m-d H:i:s', $now + self::TTL), gmdate('Y-m-d H:i:s', $now)]);

        return $code;
    }

    public function verify(int $userId, string $purpose, string $input, ?int $now = null): bool
    {
        $now ??= time();
        $code = preg_replace('/\D/', '', $input) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }

        $st = $this->pdo->prepare('SELECT id, code_hash, attempts FROM email_codes WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1');
        $st->execute([$userId, $purpose, gmdate('Y-m-d H:i:s', $now)]);
        $row = $st->fetch();
        if (! is_array($row) || (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }

        $this->pdo->prepare('UPDATE email_codes SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $row['id']]);
        if (! hash_equals((string) $row['code_hash'], $this->mac($code, $userId, $purpose))) {
            return false;
        }

        $use = $this->pdo->prepare('UPDATE email_codes SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $use->execute([gmdate('Y-m-d H:i:s', $now), (int) $row['id']]);

        return $use->rowCount() === 1;
    }

    private function mac(string $code, int $userId, string $purpose): string
    {
        return hash_hmac('sha256', "{$userId}:{$purpose}:{$code}", $this->config->getRequired('APP_KEY'));
    }
}
