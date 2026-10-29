<?php

declare(strict_types=1);

namespace App\Auth;

final class Users
{
    private const COLUMNS = 'id, email, display_name, password_hash, role, status, totp_secret_enc, totp_enrolled_at, totp_last_step';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function findByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE email = ?');
        $st->execute([self::normalizeEmail($email)]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    public function countByRole(string $role): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
        $st->execute([$role]);

        return (int) $st->fetchColumn();
    }

    public function createWithTotp(string $email, ?string $displayName, string $passwordHash, string $role, string $totpSecretEnc, int $firstStep): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO users (email, display_name, password_hash, role, status, email_verified_at, totp_secret_enc, totp_enrolled_at, totp_last_step, password_changed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, \'active\', ?, ?, ?, ?, ?, ?, ?)'
        )->execute([self::normalizeEmail($email), $displayName, $passwordHash, $role, $now, $totpSecretEnc, $now, $firstStep, $now, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    public function touchLogin(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $id]);
    }

    public function setPasswordHash(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = ? WHERE id = ?')->execute([$hash, gmdate('Y-m-d H:i:s'), $id]);
    }

    public static function publicView(array $user): array
    {
        return ['id' => (int) $user['id'], 'email' => (string) $user['email'], 'displayName' => $user['display_name'], 'role' => (string) $user['role']];
    }
}
