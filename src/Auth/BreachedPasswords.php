<?php

declare(strict_types=1);

namespace App\Auth;

final class BreachedPasswords
{
    public function __construct(private readonly string $file)
    {
        if (! is_file($this->file) || ! is_readable($this->file)) {
            throw new \RuntimeException('Breached-password file is missing or unreadable.');
        }
    }

    public function contains(string $password): bool
    {
        $target = strtoupper(sha1($password));
        $fh = fopen($this->file, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Could not open the breached-password file.');
        }

        try {
            $lo = 0;
            $hi = (int) (fstat($fh)['size'] ?? 0);
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                fseek($fh, max(0, $mid - 1));
                if ($mid > 0) {
                    fgets($fh);
                }
                $pos = (int) ftell($fh);
                $line = $pos < $hi ? fgets($fh) : false;
                if ($line === false) {
                    $hi = $mid;
                    continue;
                }

                $cmp = strcmp(substr($line, 0, 40), $target);
                if ($cmp === 0) {
                    return true;
                }
                if ($cmp < 0) {
                    $lo = (int) ftell($fh);
                } else {
                    $hi = $mid;
                }
            }
        } finally {
            fclose($fh);
        }

        return false;
    }
}
