<?php

declare(strict_types=1);

namespace App;

final class Config
{
    /** @param array<string, mixed> $env */
    public function __construct(
        public readonly string $root,
        public readonly string $publicDir,
        private readonly array $env,
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->env[$key] ?? getenv($key);

        return ($value === false || $value === null || $value === '') ? $default : (string) $value;
    }

    public function getRequired(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new \RuntimeException("Missing required config value: {$key}");
        }

        return $value;
    }

    public function isProduction(): bool
    {
        return $this->get('APP_ENV', 'production') === 'production';
    }
}
