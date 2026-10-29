<?php

declare(strict_types=1);

namespace App\Auth;

use Psr\Http\Message\ServerRequestInterface;

final class ClientIp
{
    public static function from(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public static function pack(string $ip): string
    {
        $packed = @inet_pton($ip);

        return $packed === false ? "\0\0\0\0" : $packed;
    }
}
