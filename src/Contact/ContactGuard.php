<?php

declare(strict_types=1);

namespace App\Contact;

use App\Config;

/**
 * Stateless anti-spam token for public forms: "<issued_at>.<nonce>.<hmac>". A submission needs a token that is at least
 * MIN_AGE seconds old (bots post instantly) and no older than MAX_AGE. Signed with APP_KEY, so no session is needed.
 */
final class ContactGuard
{
    public const MIN_AGE = 4;
    public const MAX_AGE = 7200;

    public function __construct(private readonly Config $config)
    {
    }

    public function issue(?int $now = null): string
    {
        $issued = (string) ($now ?? time());
        $nonce = bin2hex(random_bytes(8));

        return "{$issued}.{$nonce}." . $this->sign($issued, $nonce);
    }

    /** @return 'ok'|'invalid'|'too_fast'|'expired' */
    public function check(mixed $token, ?int $now = null): string
    {
        if (! is_string($token) || preg_match('/^(\d{9,11})\.([0-9a-f]{16})\.([0-9a-f]{64})$/', $token, $m) !== 1) {
            return 'invalid';
        }
        if (! hash_equals($this->sign($m[1], $m[2]), $m[3])) {
            return 'invalid';
        }
        $age = ($now ?? time()) - (int) $m[1];

        return match (true) {
            $age < self::MIN_AGE => 'too_fast',
            $age > self::MAX_AGE => 'expired',
            default => 'ok',
        };
    }

    private function sign(string $issued, string $nonce): string
    {
        return hash_hmac('sha256', "contact:{$issued}:{$nonce}", $this->config->getRequired('APP_KEY'));
    }
}
