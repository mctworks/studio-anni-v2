<?php

declare(strict_types=1);

namespace App\Payments;

use App\Config;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripeGateway implements PaymentGateway
{
    private ?StripeClient $client = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function publishableKey(): string
    {
        return $this->config->get('STRIPE_PUBLISHABLE_KEY') ?? throw new GatewayException(GatewayException::NOT_CONFIGURED);
    }

    public function createIntent(int $amountCents, array $metadata, string $description, string $idempotencyKey): array
    {
        try {
            $intent = $this->client()->paymentIntents->create([
                'amount' => $amountCents,
                'currency' => 'usd',
                'automatic_payment_methods' => ['enabled' => true],
                'description' => $description,
                'metadata' => $metadata,
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (ApiErrorException $e) {
            throw new GatewayException('api_error', $e->getMessage(), $e);
        }

        return ['id' => (string) $intent->id, 'client_secret' => (string) $intent->client_secret];
    }

    public function retrieve(string $intentId): array
    {
        try {
            $intent = $this->client()->paymentIntents->retrieve($intentId);
        } catch (ApiErrorException $e) {
            throw new GatewayException('api_error', $e->getMessage(), $e);
        }

        return [
            'id' => (string) $intent->id,
            'status' => (string) $intent->status,
            'amount' => (int) $intent->amount,
            'received' => (int) $intent->amount_received,
            'currency' => (string) $intent->currency,
        ];
    }

    public function cancel(string $intentId): void
    {
        try {
            $this->client()->paymentIntents->cancel($intentId);
        } catch (ApiErrorException $e) {
            throw new GatewayException('api_error', $e->getMessage(), $e);
        }
    }

    public function refund(string $intentId, string $idempotencyKey): void
    {
        try {
            $this->client()->refunds->create(['payment_intent' => $intentId], ['idempotency_key' => $idempotencyKey]);
        } catch (ApiErrorException $e) {
            throw new GatewayException('api_error', $e->getMessage(), $e);
        }
    }

    public function verifyEvent(string $payload, string $signature): array
    {
        $secret = $this->config->get('STRIPE_WEBHOOK_SECRET') ?? throw new GatewayException(GatewayException::NOT_CONFIGURED);
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException | UnexpectedValueException $e) {
            throw new GatewayException(GatewayException::BAD_SIGNATURE, $e->getMessage(), $e);
        }
        $object = $event->data->object ?? null;
        $id = is_object($object) && isset($object->id) ? (string) $object->id : null;

        return ['id' => (string) $event->id, 'type' => (string) $event->type, 'object_id' => $id];
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient(
            $this->config->get('STRIPE_SECRET_KEY') ?? throw new GatewayException(GatewayException::NOT_CONFIGURED)
        );
    }
}
