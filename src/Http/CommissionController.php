<?php

declare(strict_types=1);

namespace App\Http;

use App\Commissions\Pricing;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CommissionController
{
    public function __construct(private readonly Pricing $pricing)
    {
    }

    public function info(Request $request, Response $response): Response
    {
        $out = Json::write($response, [
            'availability' => $this->pricing->availability(),
            'deposit_percent' => $this->pricing->depositPercent(),
            'fees' => $this->pricing->fees(),
            'prices' => $this->pricing->publicTable(),
            'sizes' => array_values(array_filter($this->pricing->table(), static fn (array $r): bool => $r['pet_cents'] !== null || $r['human_cents'] !== null)),
        ]);

        return $out->withHeader('Cache-Control', 'public, max-age=60');
    }
}
