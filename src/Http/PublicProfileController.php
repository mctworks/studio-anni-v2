<?php

declare(strict_types=1);

namespace App\Http;

use App\Customers\Customers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PublicProfileController
{
    public function __construct(private readonly Customers $customers)
    {
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $profile = $this->customers->publicByUsername($args['username']);

        return $profile === null ? Json::write($response, ['error' => 'not found'], 404) : Json::write($response, ['data' => $profile]);
    }
}
