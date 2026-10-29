<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config;

final class PasswordService
{
    public const MIN_LENGTH = 12;
    public const MAX_BYTES = 1024;

    private ?string $dummyHash = null;

    public function __construct(private readonly Config $config, private readonly ?BreachedPasswords $breached = null)
    {
    }

    public function validate(string $password): array
    {
        $errors = [];
        if (mb_strlen($password, 'UTF-8') < self::MIN_LENGTH) {
            $errors[] = 'too_short';
        }
        if (strlen($password) > self::MAX_BYTES) {
            $errors[] = 'too_long';
        }
        if ($errors === [] && $this->breached !== null && $this->breached->contains($password)) {
            $errors[] = 'breached';
        }

        return $errors;
    }

    public function hash(string $password): string
    {
        [$algo, $options] = $this->algorithm();

        return password_hash($password, $algo, $options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        [$algo, $options] = $this->algorithm();

        return password_needs_rehash($hash, $algo, $options);
    }

    public function dummyVerify(string $password): bool
    {
        $this->dummyHash ??= $this->hash(bin2hex(random_bytes(16)));
        password_verify($password, $this->dummyHash);

        return false;
    }

    private function algorithm(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return [PASSWORD_ARGON2ID, [
                'memory_cost' => (int) $this->config->get('ARGON2_MEMORY_KIB', '19456'),
                'time_cost' => (int) $this->config->get('ARGON2_TIME', '2'),
                'threads' => (int) $this->config->get('ARGON2_THREADS', '1'),
            ]];
        }

        return [PASSWORD_BCRYPT, ['cost' => 12]];
    }
}
