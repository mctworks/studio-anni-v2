<?php

declare(strict_types=1);

namespace App\Http;

use App\Payments\CartService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CartController
{
    public function __construct(private readonly CartService $cart)
    {
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->cartJson($response, (int) $request->getAttribute('user')['id']);
    }

    public function add(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user')['id'];
        $body = $request->getParsedBody();
        $id = is_array($body) && is_int($body['piece_id'] ?? null) ? $body['piece_id'] : 0;
        $error = $id > 0 ? $this->cart->add($userId, $id) : 'invalid_request';
        if ($error !== null) {
            return Json::write($response, ['error' => $error], $error === 'invalid_request' ? 400 : 409);
        }

        return $this->cartJson($response, $userId);
    }

    public function remove(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user')['id'];
        $this->cart->remove($userId, (int) $args['pieceId']);

        return $this->cartJson($response, $userId);
    }

    private function cartJson(Response $response, int $userId): Response
    {
        $lines = $this->cart->lines($userId);
        $subtotal = array_sum(array_column(array_filter($lines, static fn (array $l): bool => $l['ok']), 'price_cents'));

        return Json::write($response, ['lines' => $lines, 'subtotal_cents' => $subtotal]);
    }
}
