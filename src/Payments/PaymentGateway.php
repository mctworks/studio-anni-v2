<?php

declare(strict_types=1);

namespace App\Payments;

interface PaymentGateway
{
    public function publishableKey(): string;

    /** @return array{id: string, client_secret: string} @throws GatewayException */
    public function createIntent(int $amountCents, array $metadata, string $description, string $idempotencyKey): array;

    /** @return array{id: string, status: string, amount: int, received: int, currency: string} @throws GatewayException */
    public function retrieve(string $intentId): array;

    /** @throws GatewayException */
    public function cancel(string $intentId): void;

    /** @throws GatewayException */
    public function refund(string $intentId, string $idempotencyKey): void;

    /** @return array{id: string, type: string, object_id: ?string} @throws GatewayException */
    public function verifyEvent(string $payload, string $signature): array;
}
