<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure\Adapter\Stripe;

use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand;
use Gomrok\Modules\Providers\Application\Adapter\CreateSubscriptionCommand;
use Gomrok\Modules\Providers\Application\Adapter\ParsedWebhookEvent;
use Gomrok\Modules\Providers\Application\Adapter\PaymentProviderPort;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAuthenticationFailed;
use Gomrok\Modules\Providers\Application\Adapter\ProviderBillingPortalSession;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRefundResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRequestFailed;
use Gomrok\Modules\Providers\Application\Adapter\ProviderSubscriptionResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderSubscriptionStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderWebhookVerificationFailed;
use Gomrok\Modules\Providers\Application\Adapter\RawWebhook;
use Gomrok\Modules\Providers\Application\Adapter\SupportsAuthCapture;
use Gomrok\Modules\Providers\Application\Adapter\SupportsCustomerPortal;
use Gomrok\Modules\Providers\Application\Adapter\SupportsRefunds;
use Gomrok\Modules\Providers\Application\Adapter\SupportsSubscriptions;
use Gomrok\Modules\Providers\Domain\ProviderCapabilities;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclarations;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe implements the core port plus subscriptions, refunds, auth/capture,
 * and the billing portal (`Architecture.md` §8's sketch). The Stripe PHP SDK
 * is used **only** here (Hexagonal Architecture Rule 5) — nothing outside
 * this class or its factory ever references `\Stripe\*`. `getCapabilities()`
 * defers to the Phase 8 seeded declaration rather than hardcoding a second
 * copy of what Stripe supports.
 */
final readonly class StripeAdapter implements
    PaymentProviderPort,
    SupportsSubscriptions,
    SupportsRefunds,
    SupportsAuthCapture,
    SupportsCustomerPortal
{
    private const PROVIDER_TYPE_CODE = 'stripe';

    public function __construct(
        private StripeClient $client,
        private ProviderTypeDeclarations $declarations,
        private StripeStatusMapper $mapper = new StripeStatusMapper(),
    ) {
    }

    public function createPayment(CreatePaymentCommand $command): ProviderPaymentResult
    {
        return $this->createCheckoutSession($command, 'payment', null);
    }

    public function authorizePayment(CreatePaymentCommand $command): ProviderPaymentResult
    {
        return $this->createCheckoutSession($command, 'payment', 'manual');
    }

    public function capturePayment(string $providerReference, ?int $amountMinor = null): ProviderPaymentResult
    {
        try {
            $params = $amountMinor !== null ? ['amount_to_capture' => $amountMinor] : [];
            $intent = $this->client->paymentIntents->capture($providerReference, $params);
        } catch (AuthenticationException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        return new ProviderPaymentResult($intent->id, '', $intent->status);
    }

    public function cancelPayment(string $providerReference): void
    {
        try {
            $this->client->paymentIntents->cancel($providerReference);
        } catch (AuthenticationException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }
    }

    public function getPaymentStatus(string $providerReference): ProviderPaymentStatus
    {
        try {
            $session = $this->client->checkout->sessions->retrieve($providerReference, ['expand' => ['payment_intent']]);
        } catch (AuthenticationException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        $sessionStatus = $session->status ?? 'open';
        $paymentIntent = $session->payment_intent;
        $paymentIntentId = null;
        $mapped = $this->mapper->fromCheckoutSession($sessionStatus, $session->payment_status);

        if ($paymentIntent instanceof PaymentIntent) {
            $paymentIntentId = $paymentIntent->id;
            $mapped = $this->mapper->fromPaymentIntent($paymentIntent->status);
        }

        return new ProviderPaymentStatus($providerReference, $sessionStatus, $mapped, $paymentIntentId);
    }

    public function verifyWebhookSignature(RawWebhook $webhook): bool
    {
        try {
            Webhook::constructEvent($webhook->payload, $webhook->signatureHeader, $webhook->webhookSigningSecret);

            return true;
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return false;
        }
    }

    public function parseWebhook(RawWebhook $webhook): ParsedWebhookEvent
    {
        try {
            $event = Webhook::constructEvent($webhook->payload, $webhook->signatureHeader, $webhook->webhookSigningSecret);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new ProviderWebhookVerificationFailed($e->getMessage(), previous: $e);
        }

        $object = $event->data->object;
        $objectId = $object['id'] ?? null;
        $objectStatus = $object['status'] ?? null;

        /** @var array<array-key, mixed> $payload */
        $payload = $event->toArray();

        return new ParsedWebhookEvent(
            $event->id,
            $event->type,
            \is_string($objectId) ? $objectId : null,
            \is_string($objectStatus) ? $objectStatus : '',
            $payload,
        );
    }

    public function mapProviderStatusToInternalStatus(string $providerStatus): PaymentStatus
    {
        return $this->mapper->fromPaymentIntent($providerStatus);
    }

    public function getCapabilities(): ProviderCapabilities
    {
        $declaration = $this->declarations->findByCode(self::PROVIDER_TYPE_CODE);
        if ($declaration === null) {
            return ProviderCapabilities::none();
        }

        return $declaration->capabilities;
    }

    public function createSubscription(CreateSubscriptionCommand $command): ProviderSubscriptionResult
    {
        $params = [
            'mode' => 'subscription',
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($command->currencyCode),
                    'unit_amount' => $command->amountMinor,
                    'product_data' => ['name' => $command->description],
                    'recurring' => $this->recurringFor($command->interval),
                ],
                'quantity' => 1,
            ]],
            'success_url' => $command->successUrl,
            'cancel_url' => $command->cancelUrl,
            'client_reference_id' => $command->attemptReference,
            'metadata' => $command->metadata,
        ];
        if ($command->customerEmail !== null) {
            $params['customer_email'] = $command->customerEmail;
        }

        try {
            $session = $this->client->checkout->sessions->create($params);
        } catch (AuthenticationException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        return new ProviderSubscriptionResult($session->id, $session->url ?? '', $session->status ?? 'open');
    }

    public function getSubscriptionStatus(string $providerReference): ProviderSubscriptionStatus
    {
        try {
            $subscription = $this->client->subscriptions->retrieve($providerReference);
        } catch (AuthenticationException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        return new ProviderSubscriptionStatus($providerReference, $subscription->status, $this->mapper->fromSubscription($subscription->status));
    }

    public function cancelSubscription(string $providerReference): void
    {
        try {
            $this->client->subscriptions->cancel($providerReference);
        } catch (AuthenticationException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }
    }

    public function mapProviderSubscriptionStatusToInternalStatus(string $providerStatus): string
    {
        return $this->mapper->fromSubscription($providerStatus);
    }

    public function refundPayment(string $providerReference, ?int $amountMinor = null): ProviderRefundResult
    {
        try {
            $params = ['payment_intent' => $providerReference];
            if ($amountMinor !== null) {
                $params['amount'] = $amountMinor;
            }
            $refund = $this->client->refunds->create($params);
        } catch (AuthenticationException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        return new ProviderRefundResult($refund->id, $refund->amount, $refund->status ?? '');
    }

    public function createBillingPortalSession(string $providerCustomerId, string $returnUrl): ProviderBillingPortalSession
    {
        try {
            $session = $this->client->billingPortal->sessions->create([
                'customer' => $providerCustomerId,
                'return_url' => $returnUrl,
            ]);
        } catch (AuthenticationException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        return new ProviderBillingPortalSession($session->url);
    }

    private function createCheckoutSession(CreatePaymentCommand $command, string $mode, ?string $captureMethod): ProviderPaymentResult
    {
        $params = [
            'mode' => $mode,
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($command->currencyCode),
                    'unit_amount' => $command->amountMinor,
                    'product_data' => ['name' => $command->description],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $command->successUrl,
            'cancel_url' => $command->cancelUrl,
            'client_reference_id' => $command->attemptReference,
            'metadata' => $command->metadata,
        ];
        if ($command->customerEmail !== null) {
            $params['customer_email'] = $command->customerEmail;
        }
        if ($captureMethod !== null) {
            $params['payment_intent_data'] = ['capture_method' => $captureMethod];
        }

        try {
            $session = $this->client->checkout->sessions->create($params);
        } catch (AuthenticationException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        return new ProviderPaymentResult($session->id, $session->url ?? '', $session->status ?? 'open');
    }

    /**
     * @return array{interval: string, interval_count: int}
     */
    private function recurringFor(SubscriptionInterval $interval): array
    {
        return match ($interval) {
            SubscriptionInterval::Monthly => ['interval' => 'month', 'interval_count' => 1],
            SubscriptionInterval::Quarterly => ['interval' => 'month', 'interval_count' => 3],
            SubscriptionInterval::Yearly => ['interval' => 'year', 'interval_count' => 1],
        };
    }
}
