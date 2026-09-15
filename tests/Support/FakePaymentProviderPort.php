<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand;
use Gomrok\Modules\Providers\Application\Adapter\CreateSubscriptionCommand as ProviderCreateSubscriptionCommand;
use Gomrok\Modules\Providers\Application\Adapter\ParsedWebhookEvent;
use Gomrok\Modules\Providers\Application\Adapter\PaymentProviderPort;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRefundResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderSubscriptionResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderSubscriptionStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderWebhookVerificationFailed;
use Gomrok\Modules\Providers\Application\Adapter\RawWebhook;
use Gomrok\Modules\Providers\Application\Adapter\SupportsAuthCapture;
use Gomrok\Modules\Providers\Application\Adapter\SupportsRefunds;
use Gomrok\Modules\Providers\Application\Adapter\SupportsSubscriptions;
use Gomrok\Modules\Providers\Domain\ProviderCapabilities;

/**
 * A minimal, fully in-memory {@see PaymentProviderPort} for handler tests
 * that need a real port instance without a real SDK/HTTP call — records the
 * last {@see CreatePaymentCommand} it received so tests can assert on it.
 * Implements the optional {@see SupportsRefunds}/{@see SupportsAuthCapture}/
 * {@see SupportsSubscriptions} capability interfaces unconditionally
 * (Phase 24/26) — capability *gating* in these tests is exercised via
 * `InMemoryProviderTypeDeclarations`, not by this double declining the
 * interface.
 */
final class FakePaymentProviderPort implements PaymentProviderPort, SupportsRefunds, SupportsAuthCapture, SupportsSubscriptions
{
    public ?CreatePaymentCommand $lastCommand = null;

    public ?string $lastRefundReference = null;

    public ?int $lastRefundAmountMinor = null;

    public ?string $lastCaptureReference = null;

    public ?string $lastCancelReference = null;

    public ?ProviderCreateSubscriptionCommand $lastCreateSubscriptionCommand = null;

    public ?string $lastCancelSubscriptionReference = null;

    private ?ProviderAdapterException $throwOnCreatePayment = null;

    private ?ProviderAdapterException $throwOnRefund = null;

    private ?ProviderAdapterException $throwOnCapture = null;

    private ?ProviderAdapterException $throwOnCreateSubscription = null;

    private ?ProviderAdapterException $throwOnCancelSubscription = null;

    private ProviderSubscriptionResult $createSubscriptionResult;

    private ProviderSubscriptionStatus $subscriptionStatusResult;

    private PaymentStatus $paymentStatus = PaymentStatus::Pending;

    private string $paymentRawStatus = 'open';

    private ?string $paymentIntentReference = null;

    private ?string $subscriptionReference = null;

    private ParsedWebhookEvent $webhookParseResult;

    private ?ProviderWebhookVerificationFailed $throwOnParseWebhook = null;

    public ?RawWebhook $lastWebhook = null;

    public function __construct(
        private ProviderPaymentResult $createPaymentResult = new ProviderPaymentResult('ref_1', 'https://provider.example/checkout/ref_1', 'open'),
        private ProviderRefundResult $refundResult = new ProviderRefundResult('re_1', 0, 'refunded'),
        private ProviderPaymentResult $captureResult = new ProviderPaymentResult('ref_1', '', 'succeeded'),
    ) {
        $this->webhookParseResult = new ParsedWebhookEvent('evt_1', 'payment.updated', null, 'open', []);
        $this->createSubscriptionResult = new ProviderSubscriptionResult('sub_1', 'https://provider.example/checkout/sub_1', 'incomplete');
        $this->subscriptionStatusResult = new ProviderSubscriptionStatus('sub_1', 'active', 'active');
    }

    public function throwOnCreatePayment(ProviderAdapterException $exception): void
    {
        $this->throwOnCreatePayment = $exception;
    }

    public function throwOnRefund(ProviderAdapterException $exception): void
    {
        $this->throwOnRefund = $exception;
    }

    public function throwOnCapture(ProviderAdapterException $exception): void
    {
        $this->throwOnCapture = $exception;
    }

    public function throwOnCreateSubscription(ProviderAdapterException $exception): void
    {
        $this->throwOnCreateSubscription = $exception;
    }

    public function throwOnCancelSubscription(ProviderAdapterException $exception): void
    {
        $this->throwOnCancelSubscription = $exception;
    }

    public function createSubscriptionResult(ProviderSubscriptionResult $result): void
    {
        $this->createSubscriptionResult = $result;
    }

    public function subscriptionStatusResult(ProviderSubscriptionStatus $result): void
    {
        $this->subscriptionStatusResult = $result;
    }

    public function webhookParseResult(ParsedWebhookEvent $result): void
    {
        $this->webhookParseResult = $result;
    }

    public function throwOnParseWebhook(ProviderWebhookVerificationFailed $exception): void
    {
        $this->throwOnParseWebhook = $exception;
    }

    public function refundResult(ProviderRefundResult $result): void
    {
        $this->refundResult = $result;
    }

    public function captureResult(ProviderPaymentResult $result): void
    {
        $this->captureResult = $result;
    }

    /**
     * The "deeper" reference {@see \Gomrok\Modules\Payments\Application\ResolvePaymentActionContext}
     * looks for — mirrors {@see ProviderPaymentStatus::$paymentIntentReference}.
     */
    public function paymentIntentReference(?string $reference): void
    {
        $this->paymentIntentReference = $reference;
    }

    /**
     * The real provider Subscription resource id {@see ProviderPaymentStatus::$subscriptionReference}
     * surfaces (Phase 26) — mutually exclusive with `paymentIntentReference` in practice.
     */
    public function subscriptionReference(?string $reference): void
    {
        $this->subscriptionReference = $reference;
    }

    /**
     * Configures what {@see getPaymentStatus()} reports back — `$rawStatus`
     * is a raw Stripe-style status string, mapped the same way
     * `StripeStatusMapper::fromPaymentIntent()` would.
     */
    public function createPaymentResultStatus(string $rawStatus): void
    {
        $this->paymentRawStatus = $rawStatus;
        $this->paymentStatus = self::mapRawStatus($rawStatus);
    }

    public function createPayment(CreatePaymentCommand $command): ProviderPaymentResult
    {
        $this->lastCommand = $command;
        if ($this->throwOnCreatePayment !== null) {
            throw $this->throwOnCreatePayment;
        }

        return $this->createPaymentResult;
    }

    public function getPaymentStatus(string $providerReference): ProviderPaymentStatus
    {
        return new ProviderPaymentStatus($providerReference, $this->paymentRawStatus, $this->paymentStatus, $this->paymentIntentReference, $this->subscriptionReference);
    }

    public function verifyWebhookSignature(RawWebhook $webhook): bool
    {
        return true;
    }

    public function parseWebhook(RawWebhook $webhook): ParsedWebhookEvent
    {
        $this->lastWebhook = $webhook;
        if ($this->throwOnParseWebhook !== null) {
            throw $this->throwOnParseWebhook;
        }

        return $this->webhookParseResult;
    }

    public function mapProviderStatusToInternalStatus(string $providerStatus): PaymentStatus
    {
        return self::mapRawStatus($providerStatus);
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return ProviderCapabilities::none();
    }

    public function refundPayment(string $providerReference, ?int $amountMinor = null): ProviderRefundResult
    {
        $this->lastRefundReference = $providerReference;
        $this->lastRefundAmountMinor = $amountMinor;
        if ($this->throwOnRefund !== null) {
            throw $this->throwOnRefund;
        }

        return $this->refundResult;
    }

    public function authorizePayment(CreatePaymentCommand $command): ProviderPaymentResult
    {
        return $this->createPayment($command);
    }

    public function capturePayment(string $providerReference, ?int $amountMinor = null): ProviderPaymentResult
    {
        $this->lastCaptureReference = $providerReference;
        if ($this->throwOnCapture !== null) {
            throw $this->throwOnCapture;
        }

        return $this->captureResult;
    }

    public function cancelPayment(string $providerReference): void
    {
        $this->lastCancelReference = $providerReference;
    }

    public function createSubscription(ProviderCreateSubscriptionCommand $command): ProviderSubscriptionResult
    {
        $this->lastCreateSubscriptionCommand = $command;
        if ($this->throwOnCreateSubscription !== null) {
            throw $this->throwOnCreateSubscription;
        }

        return $this->createSubscriptionResult;
    }

    public function getSubscriptionStatus(string $providerReference): ProviderSubscriptionStatus
    {
        return $this->subscriptionStatusResult;
    }

    public function cancelSubscription(string $providerReference): void
    {
        $this->lastCancelSubscriptionReference = $providerReference;
        if ($this->throwOnCancelSubscription !== null) {
            throw $this->throwOnCancelSubscription;
        }
    }

    public function mapProviderSubscriptionStatusToInternalStatus(string $providerStatus): string
    {
        return strtolower(trim($providerStatus));
    }

    private static function mapRawStatus(string $rawStatus): PaymentStatus
    {
        return match ($rawStatus) {
            'paid', 'succeeded' => PaymentStatus::Paid,
            'canceled' => PaymentStatus::Canceled,
            'expired' => PaymentStatus::Expired,
            'failed' => PaymentStatus::Failed,
            'requires_action' => PaymentStatus::RequiresAction,
            'authorized', 'requires_capture' => PaymentStatus::Authorized,
            default => PaymentStatus::Pending,
        };
    }
}
