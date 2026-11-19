<?php

declare(strict_types=1);

namespace App\Contact;

final class ContactSenders
{
    public const KINDS = ['email', 'domain', 'ip'];

    private const PROTECTED_DOMAINS = [
        'studioanni.com', 'gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com',
        'live.com', 'msn.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com',
    ];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function blocked(string $ip, string $email, bool $checkIp = true): bool
    {
        $email = strtolower(trim($email));
        $keys = [['email', $email]];
        foreach (self::domains($email) as $domain) {
            $keys[] = ['domain', $domain];
        }
        if ($checkIp && ($packed = self::canonicalIp($ip)) !== null) {
            $keys[] = ['ip', $packed];
        }

        $where = implode(' OR ', array_fill(0, count($keys), '(kind = ? AND value = ?)'));
        $st = $this->pdo->prepare("SELECT id FROM blocked_senders WHERE {$where} LIMIT 1");
        $st->execute(array_merge(...$keys));
        $id = $st->fetchColumn();
        if ($id === false) {
            return false;
        }

        $this->pdo->prepare('UPDATE blocked_senders SET hits = hits + 1, last_hit_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s'), (int) $id]);

        return true;
    }

    public function log(string $name, string $email, string $ip, string $source, string $message): void
    {
        $now = time();
        $this->pdo->prepare('INSERT INTO contact_log (name, email, ip, source, excerpt, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, strtolower($email), self::canonicalIp($ip) ?? $ip, $source, mb_substr($message, 0, 300), gmdate('Y-m-d H:i:s', $now)]);
        if (random_int(1, 50) === 1) {
            $this->pdo->prepare('DELETE FROM contact_log WHERE created_at < ?')->execute([gmdate('Y-m-d H:i:s', $now - 90 * 86400)]);
        }
    }

    /** @return array{0: int, 1: list<array<string, mixed>>} */
    public function messages(int $offset, int $limit): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM contact_log')->fetchColumn();
        $st = $this->pdo->prepare('SELECT id, name, email, ip, source, excerpt, created_at FROM contact_log ORDER BY id DESC LIMIT ? OFFSET ?');
        $st->bindValue(1, $limit, \PDO::PARAM_INT);
        $st->bindValue(2, $offset, \PDO::PARAM_INT);
        $st->execute();

        return [$total, $st->fetchAll()];
    }

    /** @return array<string, mixed>|null */
    public function message(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, name, email, ip, source, excerpt, created_at FROM contact_log WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT id, kind, value, reason, hits, last_hit_at, created_at FROM blocked_senders ORDER BY id DESC')->fetchAll();
    }

    /** @return array{0: ?int, 1: ?string} new id, or an error code: invalid, protected_domain, exists */
    public function add(string $kind, string $value, ?string $reason, ?int $userId): array
    {
        $clean = match ($kind) {
            'email' => self::cleanEmail($value),
            'domain' => self::cleanDomain($value),
            'ip' => self::cleanIp($value),
            default => null,
        };
        if ($clean === null) {
            return [null, 'invalid'];
        }
        if ($kind === 'domain' && in_array($clean, self::PROTECTED_DOMAINS, true)) {
            return [null, 'protected_domain'];
        }

        $reason = trim(mb_substr($reason ?? '', 0, 200));
        try {
            $this->pdo->prepare('INSERT INTO blocked_senders (kind, value, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$kind, $clean, $reason === '' ? null : $reason, $userId, gmdate('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return [null, 'exists'];
            }
            throw $e;
        }

        return [(int) $this->pdo->lastInsertId(), null];
    }

    public function remove(int $id): bool
    {
        $st = $this->pdo->prepare('DELETE FROM blocked_senders WHERE id = ?');
        $st->execute([$id]);

        return $st->rowCount() === 1;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array{email: bool, domain: bool, ip: bool}
     */
    public static function flags(array $blocks, string $ip, string $email): array
    {
        $email = strtolower($email);
        $domains = self::domains($email);
        $packed = self::canonicalIp($ip);
        $flags = ['email' => false, 'domain' => false, 'ip' => false];
        foreach ($blocks as $b) {
            $kind = (string) $b['kind'];
            $value = (string) $b['value'];
            if (($kind === 'email' && $value === $email) || ($kind === 'domain' && in_array($value, $domains, true)) || ($kind === 'ip' && $value === $packed)) {
                $flags[$kind] = true;
            }
        }

        return $flags;
    }

    public static function domainOf(string $email): ?string
    {
        $at = strrpos($email, '@');

        return $at === false ? null : strtolower(substr($email, $at + 1));
    }

    public static function canonicalIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = @inet_pton($ip);

        return $packed === false ? null : (string) inet_ntop($packed);
    }

    /** @return list<string> */
    private static function domains(string $email): array
    {
        $domain = self::domainOf($email);
        if ($domain === null || $domain === '') {
            return [];
        }
        $parts = explode('.', $domain);
        $out = [];
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $out[] = implode('.', array_slice($parts, $i));
        }

        return $out;
    }

    private static function cleanEmail(string $value): ?string
    {
        $v = strtolower(trim($value));

        return strlen($v) <= 254 && filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : null;
    }

    private static function cleanDomain(string $value): ?string
    {
        $v = strtolower(trim($value));
        if (str_contains($v, '@')) {
            $v = substr($v, strrpos($v, '@') + 1);
        }

        return preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $v) === 1 ? $v : null;
    }

    private static function cleanIp(string $value): ?string
    {
        $v = trim($value);
        if (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return self::canonicalIp($v);
    }
}
