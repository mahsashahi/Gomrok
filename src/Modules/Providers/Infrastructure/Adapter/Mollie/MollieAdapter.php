<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure\Adapter\Mollie;

use Brick\Money\Money as BrickMoney;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand;
use Gomrok\Modules\Providers\Application\Adapter\CreateSubscriptionCommand;
use Gomrok\Modules\Providers\Application\Adapter\ParsedWebhookEvent;
use Gomrok\Modules\Providers\Application\Adapter\PaymentProviderPort;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAuthenticationFailed;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRefundResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRequestFailed;
use Gomrok\Modules\Providers\Application\Adapter\ProviderSubscriptionResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderSubscriptionStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderWebhookVerificationFailed;
use Gomrok\Modules\Providers\Application\Adapter\RawWebhook;
use Gomrok\Modules\Providers\Application\Adapter\SupportsManualPolling;
use Gomrok\Modules\Providers\Application\Adapter\SupportsRefunds;
use Gomrok\Modules\Providers\Application\Adapter\SupportsSubscriptions;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderCapabilities;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclarations;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\ForbiddenException;
use Mollie\Api\Exceptions\UnauthorizedException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Types\PaymentMethod as MolliePaymentMethod;
use Mollie\Api\Types\SequenceType;

/**
 * Mollie implements the core port plus refunds, subscriptions (Phase 22 Q6 —
 * provisional: `createSubscription()` only performs the first-payment/mandate
 * step; the real Mollie Subscription resource is created later, out-of-band,
 * once Phase 25's webhook processing confirms the mandate), and manual status
 * polling — never the billing/customer portal (Phase 22: corrected a stale
 * Phase 10 seed; Mollie has no such product). The Mollie PHP SDK is used
 * **only** here (Hexagonal Architecture Rule 5). `getCapabilities()` defers
 * to the Phase 8 seeded declaration, same as `StripeAdapter`.
 */
final readonly class MollieAdapter implements
    PaymentProviderPort,
    SupportsRefunds,
    SupportsSubscriptions,
    SupportsManualPolling
{
    private const PROVIDER_TYPE_CODE = 'mollie';

    public function __construct(
        private MollieApiClient $client,
        private ProviderTypeDeclarations $declarations,
        private MollieStatusMapper $mapper = new MollieStatusMapper(),
    ) {
    }

    public function createPayment(CreatePaymentCommand $command): ProviderPaymentResult
    {
        $params = [
            'amount' => $this->toMollieAmount($command->amountMinor, $command->currencyCode),
            'description' => $command->description,
            'redirectUrl' => $command->successUrl,
            'cancelUrl' => $command->cancelUrl,
            // Mollie has no dedicated "your own reference" field — mirrors
            // Stripe's client_reference_id for dashboard-side reconciliation.
            'metadata' => [...$command->metadata, 'attempt_reference' => $command->attemptReference],
        ];

        $method = $this->mollieMethod($command->paymentMethod);
        if ($method !== null) {
            $params['method'] = $method;
        }

        try {
            $payment = $this->client->payments->create($params);
        } catch (UnauthorizedException|ForbiddenException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        return new ProviderPaymentResult($payment->id, $payment->getCheckoutUrl() ?? '', $payment->status);
    }

    public function getPaymentStatus(string $providerReference): ProviderPaymentStatus
    {
        return $this->fetchPaymentStatus($providerReference);
    }

    public function pollPaymentStatus(string $providerReference): ProviderPaymentStatus
    {
        return $this->fetchPaymentStatus($providerReference);
    }

    public function verifyWebhookSignature(RawWebhook $webhook): bool
    {
        $id = $this->extractPaymentId($webhook->payload);
        if ($id === null) {
            return false;
        }

        try {
            // Mollie sends no signature at all — the only real verification
            // possible is re-fetching the resource with our own credentials;
            // a forged id either doesn't exist or belongs to someone else's
            // account and fails authentication either way.
            $this->client->payments->get($id);

            return true;
        } catch (ApiException) {
            return false;
        }
    }

    public function parseWebhook(RawWebhook $webhook): ParsedWebhookEvent
    {
        $id = $this->extractPaymentId($webhook->payload);
        if ($id === null) {
            throw new ProviderWebhookVerificationFailed('Mollie webhook payload did not contain a payment id.');
        }

        try {
            $payment = $this->client->payments->get($id);
        } catch (ApiException $e) {
            throw new ProviderWebhookVerificationFailed($e->getMessage(), previous: $e);
        }

        /** @var array<array-key, mixed> $payload */
        $payload = (array) json_decode((string) json_encode($payment), true);

        return new ParsedWebhookEvent($payment->id, 'payment.updated', $payment->id, $payment->status, $payload);
    }

    public function mapProviderStatusToInternalStatus(string $providerStatus): PaymentStatus
    {
        return $this->mapper->fromPaymentStatus($providerStatus);
    }

    public function getCapabilities(): ProviderCapabilities
    {
        $declaration = $this->declarations->findByCode(self::PROVIDER_TYPE_CODE);
        if ($declaration === null) {
            return ProviderCapabilities::none();
        }

        return $declaration->capabilities;
    }

    public function refundPayment(string $providerReference, ?int $amountMinor = null): ProviderRefundResult
    {
        try {
            $payment = $this->client->payments->get($providerReference);

            // Mollie's HTTP API allows omitting `amount` for a full refund, but
            // this SDK version's request factory requires the key regardless
            // (`MoneyFactory` throws on a missing `amount`) — a `null`
            // `$amountMinor` (SupportsRefunds's "refund the remainder"
            // contract) is satisfied by explicitly passing the payment's own
            // full amount, which is equivalent.
            $paymentCurrency = $this->stringValue($payment->amount->currency);
            $amount = $amountMinor !== null
                ? $this->toMollieAmount($amountMinor, $paymentCurrency)
                : ['currency' => $paymentCurrency, 'value' => $this->stringValue($payment->amount->value)];

            $refund = $this->client->paymentRefunds->createFor($payment, ['amount' => $amount]);
        } catch (UnauthorizedException|ForbiddenException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        $refundedMinor = $this->toMinorUnits($this->stringValue($refund->amount->value), $this->stringValue($refund->amount->currency));

        return new ProviderRefundResult($refund->id, $refundedMinor, $refund->status);
    }

    /**
     * Phase 22 Q6 — provisional. Mollie has no single-call "create a
     * subscription" flow: a customer must first authorize recurring charges
     * via a one-off "first payment" (`sequenceType=first`), which only
     * produces a mandate once it succeeds; the real Mollie Subscription
     * resource can only be created afterward. This method performs exactly
     * that first-payment step and returns its checkout URL — the
     * `providerReference` returned here is a **payment** id (`tr_...`), not
     * yet a real subscription id. Creating the actual Subscription resource
     * once the mandate is confirmed is Phase 25's job (webhook processing).
     */
    public function createSubscription(CreateSubscriptionCommand $command): ProviderSubscriptionResult
    {
        $customerParams = [];
        if ($command->customerEmail !== null) {
            $customerParams['email'] = $command->customerEmail;
        }

        try {
            $customer = $this->client->customers->create($customerParams);

            $payment = $this->client->payments->create([
                'amount' => $this->toMollieAmount($command->amountMinor, $command->currencyCode),
                'description' => $command->description,
                'redirectUrl' => $command->successUrl,
                'cancelUrl' => $command->cancelUrl,
                'sequenceType' => SequenceType::FIRST,
                'customerId' => $customer->id,
                'metadata' => [...$command->metadata, 'attempt_reference' => $command->attemptReference],
            ]);
        } catch (UnauthorizedException|ForbiddenException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        return new ProviderSubscriptionResult($payment->id, $payment->getCheckoutUrl() ?? '', $payment->status);
    }

    /**
     * Provisional (Phase 22 Q6) — `$providerReference` is the first-payment
     * id `createSubscription()` returned this phase, not a real Mollie
     * subscription id, so this reports whether the mandate-forming payment
     * succeeded, not an ongoing subscription's status.
     */
    public function getSubscriptionStatus(string $providerReference): ProviderSubscriptionStatus
    {
        $status = $this->fetchPaymentStatus($providerReference);

        return new ProviderSubscriptionStatus($providerReference, $status->rawStatus, $this->mapper->fromSubscription($status->rawStatus));
    }

    /**
     * Provisional (Phase 22 Q6) — cancels the first-payment resource
     * `createSubscription()` created (only possible while it is still
     * `open`); there is no real Mollie Subscription resource to cancel yet
     * this phase.
     */
    public function cancelSubscription(string $providerReference): void
    {
        try {
            $this->client->payments->cancel($providerReference);
        } catch (UnauthorizedException|ForbiddenException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }
    }

    public function mapProviderSubscriptionStatusToInternalStatus(string $providerStatus): string
    {
        return $this->mapper->fromSubscription($providerStatus);
    }

    private function fetchPaymentStatus(string $providerReference): ProviderPaymentStatus
    {
        try {
            $payment = $this->client->payments->get($providerReference);
        } catch (UnauthorizedException|ForbiddenException $e) {
            throw new ProviderAuthenticationFailed($e->getMessage(), previous: $e);
        } catch (ApiException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        return new ProviderPaymentStatus($providerReference, $payment->status, $this->mapper->fromPaymentStatus($payment->status));
    }

    /**
     * `null` means "no restriction" — Mollie shows its own method picker.
     * `BankHostedCard` (Ziraat-only) has no Mollie equivalent and is never
     * expected to reach this adapter.
     *
     * @return non-empty-string|null
     */
    private function mollieMethod(?PaymentMethod $method): ?string
    {
        return match ($method) {
            PaymentMethod::Card => MolliePaymentMethod::CREDITCARD,
            PaymentMethod::PayPal => MolliePaymentMethod::PAYPAL,
            PaymentMethod::Ideal => MolliePaymentMethod::IDEAL,
            PaymentMethod::Bancontact => MolliePaymentMethod::BANCONTACT,
            PaymentMethod::SepaDirectDebit => MolliePaymentMethod::DIRECTDEBIT,
            default => null,
        };
    }

    /**
     * @return array{currency: string, value: string}
     */
    private function toMollieAmount(int $amountMinor, string $currencyCode): array
    {
        return [
            'currency' => strtoupper($currencyCode),
            'value' => Money::fromMinor($amountMinor, Currency::of($currencyCode))->amount(),
        ];
    }

    private function toMinorUnits(string $decimalValue, string $currencyCode): int
    {
        return BrickMoney::of($decimalValue, strtoupper($currencyCode))->getMinorAmount()->toInt();
    }

    /**
     * Mollie's resource properties are untyped (`@var \stdClass`), so PHPStan
     * sees `mixed` — a real API response is always a string here; this turns
     * an unexpected shape into a clear adapter fault rather than a silent
     * cast.
     */
    private function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new ProviderRequestFailed('Mollie returned an unexpected (non-string) amount field.');
        }

        return $value;
    }

    /**
     * Mollie's classic per-payment webhook is a form-encoded POST body of
     * just `id=tr_xxx` — no event envelope, no signature (see `RawWebhook`'s
     * docblock, Phase 22 Q5). Falls back to JSON in case a caller already
     * decoded the body differently.
     */
    private function extractPaymentId(string $payload): ?string
    {
        parse_str($payload, $parsed);
        $id = $parsed['id'] ?? null;
        if (\is_string($id) && $id !== '') {
            return $id;
        }

        $decoded = json_decode($payload, true);
        if (\is_array($decoded) && \is_string($decoded['id'] ?? null) && $decoded['id'] !== '') {
            return $decoded['id'];
        }

        return null;
    }
}
