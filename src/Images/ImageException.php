<?php

declare(strict_types=1);

namespace App\Images;

final class ImageException extends \RuntimeException
{
    public function __construct(public readonly string $error, public readonly int $status = 422)
    {
        parent::__construct($error);
    }
}
