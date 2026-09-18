<?php

declare(strict_types=1);

namespace Gomrok\Modules\Webhooks\Application\ProcessWebhookEvent;

use Gomrok\Modules\Payments\Application\PaymentDirectory;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionCommand;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment\RecordSubscriptionPaymentCommand;
use Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment\RecordSubscriptionPaymentHandler;
use Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment\RecordSubscriptionPaymentResult;
use Gomrok\Modules\Webhooks\Domain\WebhookEvent;
use Gomrok\Modules\Webhooks\Domain\WebhookEventRepository;
use Gomrok\Shared\Application\ErrorLog\ErrorLogEntry;
use Gomrok\Shared\Application\ErrorLog\ErrorLogLevel;
use Gomrok\Shared\Application\ErrorLog\ErrorLogWriter;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * The shared webhook processor (Phase 25 Q3, user-specified) — called inline
 * right after a {@see WebhookEvent} is stored, and again later by the
 * `webhook:retry-pending` cron job for anything left `retry_pending`. Both
 * callers run the exact same code; retrying never re-verifies the provider
 * signature or re-contacts the provider — it replays the already-parsed
 * `eventId`/`rawStatus`/`providerReference` stored on the row, so the only
 * thing a retry can do differently is find state (a `Payment`, a gateway
 * reference) that didn't exist on the first attempt.
 *
 * Webhooks only ever update an **existing** `Payment` (Phase 25 Q1) — a
 * webhook resolving to a checkout attempt with no `Payment` yet is left
 * `retry_pending` rather than driving the attempt to `Confirmed` itself; it
 * self-heals on a later retry once the browser-return flow (or an
 * authenticated status poll) creates the `Payment`.
 *
 * The actual status transition is delegated to
 * {@see RecordProviderTransactionHandler} — `PaymentStatus::transitionTo()`'s
 * own same-status-is-a-no-op / illegal-transition-rejected guard is what
 * makes redelivery and retry safe against duplicate state transitions; this
 * handler does not duplicate that logic.
 */
final readonly class ProcessWebhookEventHandler
{
    public const MAX_ATTEMPTS = 5;

    /**
     * @var list<GatewayReferenceType>
     */
    private const REFERENCE_LOOKUP_ORDER = [
        GatewayReferenceType::PaymentIntent,
        GatewayReferenceType::CheckoutSession,
        GatewayReferenceType::Order,
        GatewayReferenceType::Transaction,
    ];

    public function __construct(
        private WebhookEventRepository $events,
        private GatewayReferenceRepository $gatewayReferences,
        private PaymentDirectory $payments,
        private ProviderAdapterFactory $adapterFactory,
        private RecordProviderTransactionHandler $recordTransaction,
        private RecordSubscriptionPaymentHandler $recordSubscriptionPayment,
        private ErrorLogWriter $errorLog,
        private ClockInterface $clock,
    ) {
    }

    public function process(WebhookEvent $event): ProcessWebhookEventResult
    {
        $now = $this->clock->now();
        $event->markProcessing($now);
        $this->events->save($event);

        try {
            return $this->attempt($event);
        } catch (Throwable $e) {
            $this->errorLog->log(ErrorLogEntry::fromThrowable(
                $e,
                'webhook',
                ErrorLogLevel::Error,
                $event->clientId(),
                null,
                ['webhook_event_id' => $event->id(), 'provider_account_id' => $event->providerAccountId()],
            ));

            return $this->recordFailure($event, 'webhook.unexpected_error', $e->getMessage());
        }
    }

    private function attempt(WebhookEvent $event): ProcessWebhookEventResult
    {
        $providerReference = $event->providerReference();
        $rawStatus = $event->rawStatus();
        if ($providerReference === null || $rawStatus === null) {
            return $this->recordFinalFailure($event, 'webhook.unparsed_event', 'This event has no resolved provider reference or status.');
        }

        $reference = $this->resolveGatewayReference($event->providerAccountId(), $providerReference);

        // A subscription renewal charge (Phase 29 Q2): its own provider
        // reference is a brand-new id never seen before, so the lookup above
        // always misses. Resolve via the subscription it belongs to instead,
        // and route to RecordSubscriptionPaymentHandler — the same fix
        // serves Stripe's billing-engine webhooks and Mollie's real
        // Subscription resource identically.
        if ($reference === null && $event->subscriptionReference() !== null) {
            return $this->attemptSubscriptionRenewal($event, $providerReference, $rawStatus, $event->subscriptionReference());
        }

        if ($reference === null) {
            return $this->recordFailure($event, 'webhook.reference_not_found', "No gateway reference matches provider reference '{$providerReference}'.");
        }

        $paymentId = $reference->paymentId;
        if ($paymentId === null) {
            \assert($reference->checkoutAttemptId !== null);
            $payment = $this->payments->findByCheckoutAttemptId($reference->checkoutAttemptId);
            if ($payment === null) {
                return $this->recordFailure($event, 'webhook.payment_not_found_yet', 'The matching checkout attempt has not been converted to a payment yet.');
            }
            $paymentId = $payment->id;
        }

        try {
            $mappedStatus = $this->adapterFactory->for($event->providerAccountId())->mapProviderStatusToInternalStatus($rawStatus);
        } catch (UnsupportedProviderType $e) {
            return $this->recordFinalFailure($event, 'webhook.provider_not_implemented', $e->getMessage());
        }

        $recorded = $this->recordTransaction->handle(new RecordProviderTransactionCommand(
            clientId: $event->clientId(),
            paymentId: $paymentId,
            providerAccountId: $event->providerAccountId(),
            kind: 'webhook',
            providerStatusRaw: $rawStatus,
            newStatus: $mappedStatus->value,
            responsePayload: $event->payloadArray(),
            attemptOutcome: 'succeeded',
        ));

        if ($recorded->isErr()) {
            // A definitive domain-rule rejection (e.g. an illegal status
            // transition) — deterministic, not transient, so retrying the
            // exact same replayed status will never change the outcome.
            return $this->recordFinalFailure($event, $recorded->error()->code, $recorded->error()->message);
        }

        $now = $this->clock->now();
        $event->markProcessed($now, $paymentId);
        $this->events->save($event);

        return new ProcessWebhookEventResult($this->requireId($event), 'processed');
    }

    private function attemptSubscriptionRenewal(WebhookEvent $event, string $providerReference, string $rawStatus, string $subscriptionReference): ProcessWebhookEventResult
    {
        $reference = $this->gatewayReferences->findByReference($event->providerAccountId(), GatewayReferenceType::Subscription, $subscriptionReference);
        if ($reference === null || $reference->subscriptionId === null) {
            return $this->recordFailure($event, 'webhook.subscription_not_found_yet', "No subscription matches subscription reference '{$subscriptionReference}'.");
        }

        try {
            $mappedStatus = $this->adapterFactory->for($event->providerAccountId())->mapProviderStatusToInternalStatus($rawStatus);
        } catch (UnsupportedProviderType $e) {
            return $this->recordFinalFailure($event, 'webhook.provider_not_implemented', $e->getMessage());
        }

        $recorded = $this->recordSubscriptionPayment->handle(new RecordSubscriptionPaymentCommand(
            clientId: $event->clientId(),
            subscriptionId: $reference->subscriptionId,
            providerPaymentReference: $providerReference,
            rawStatus: $rawStatus,
            mappedStatus: $mappedStatus->value,
        ));

        if ($recorded->isErr()) {
            // Same reasoning as the payment path: a definitive domain-rule
            // rejection will never change on a replay of the same status.
            return $this->recordFinalFailure($event, $recorded->error()->code, $recorded->error()->message);
        }

        $result = $recorded->value();
        \assert($result instanceof RecordSubscriptionPaymentResult);

        $now = $this->clock->now();
        $event->markProcessed($now, $result->paymentId);
        $this->events->save($event);

        return new ProcessWebhookEventResult($this->requireId($event), 'processed');
    }

    private function resolveGatewayReference(int $providerAccountId, string $providerReference): ?GatewayReference
    {
        foreach (self::REFERENCE_LOOKUP_ORDER as $type) {
            $reference = $this->gatewayReferences->findByReference($providerAccountId, $type, $providerReference);
            if ($reference !== null) {
                return $reference;
            }
        }

        return null;
    }

    private function recordFailure(WebhookEvent $event, string $errorCode, string $errorMessage): ProcessWebhookEventResult
    {
        $now = $this->clock->now();
        if ($event->attemptCount() + 1 >= self::MAX_ATTEMPTS) {
            $event->markFailed($now, $errorCode, $errorMessage);
            $outcome = 'failed';
        } else {
            $event->markRetryPending($now, $errorCode, $errorMessage);
            $outcome = 'retry_pending';
        }
        $this->events->save($event);

        return new ProcessWebhookEventResult($this->requireId($event), $outcome);
    }

    private function recordFinalFailure(WebhookEvent $event, string $errorCode, string $errorMessage): ProcessWebhookEventResult
    {
        $event->markFailed($this->clock->now(), $errorCode, $errorMessage);
        $this->events->save($event);

        return new ProcessWebhookEventResult($this->requireId($event), 'failed');
    }

    private function requireId(WebhookEvent $event): int
    {
        $id = $event->id();
        \assert($id !== null);

        return $id;
    }
}
