<?php

declare(strict_types=1);

namespace App\Shipping;

/** No shipping price can be given. reason: 'oversized' (no box fits) or 'usps' (rate lookup failed). */
final class ShippingUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Shipping unavailable: {$reason}");
    }
}