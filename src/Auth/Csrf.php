<?php

declare(strict_types=1);

namespace App\Auth;

final class Csrf
{
    private const KEY = 'csrf_token';

    public static function token(array &$session): string
    {
        if (! is_string($session[self::KEY] ?? null) || $session[self::KEY] === '') {
            $session[self::KEY] = bin2hex(random_bytes(32));
        }

        return $session[self::KEY];
    }

    public static function valid(array $session, ?string $submitted): bool
    {
        $expected = $session[self::KEY] ?? null;

        return is_string($expected) && $expected !== '' && is_string($submitted) && hash_equals($expected, $submitted);
    }
}
