<?php

declare(strict_types=1);

namespace App\Payments;

class GatewayException extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const BAD_SIGNATURE = 'bad_signature';

    public function __construct(public readonly string $reason, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : "Payment gateway: {$reason}", 0, $previous);
    }
}
