<?php

declare(strict_types=1);

namespace App\Payments;

final class Locking
{
    public static function forUpdate(\PDO $pdo): string
    {
        return $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
