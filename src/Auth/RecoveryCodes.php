<?php

declare(strict_types=1);

namespace App\Auth;

final class RecoveryCodes
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const LENGTH = 10;
    private const COUNT = 10;

    public function __construct(private readonly \PDO $pdo, private readonly PasswordService $passwords)
    {
    }

    public function issue(int $userId): array
    {
        $codes = [];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM recovery_codes WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
            $insert = $this->pdo->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
            for ($i = 0; $i < self::COUNT; $i++) {
                $raw = '';
                for ($j = 0; $j < self::LENGTH; $j++) {
                    $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
                }
                $insert->execute([$userId, $this->passwords->hash($raw)]);
                $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $codes;
    }

    public function redeem(int $userId, string $input): bool
    {
        $code = $this->normalize($input);
        if ($code === null) {
            return false;
        }

        $st = $this->pdo->prepare('SELECT id, code_hash FROM recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $st->execute([$userId]);
        foreach ($st->fetchAll() as $row) {
            if ($this->passwords->verify($code, (string) $row['code_hash'])) {
                $use = $this->pdo->prepare('UPDATE recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL');
                $use->execute([gmdate('Y-m-d H:i:s'), (int) $row['id']]);

                return $use->rowCount() === 1;
            }
        }

        return false;
    }

    public function remaining(int $userId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $st->execute([$userId]);

        return (int) $st->fetchColumn();
    }

    private function normalize(string $input): ?string
    {
        $code = preg_replace('/[^a-z0-9]/', '', strtolower($input)) ?? '';

        return strlen($code) === self::LENGTH ? $code : null;
    }
}
