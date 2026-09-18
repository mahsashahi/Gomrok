<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\CapturePayment;

use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionCommand;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionResult;
use Gomrok\Modules\Payments\Application\ResolvePaymentActionContext;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentRepository;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\SupportsAuthCapture;
use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;

/**
 * `POST /api/v1/payments/{id}/capture` (Phase 24) — the second half of an
 * authorize-then-capture flow. Only meaningful from `authorized` (the
 * business precondition {@see PaymentStatus::allowedNextStatuses()}'s graph
 * doesn't by itself express, since `Created`/`Pending` can also reach `Paid`
 * directly via the immediate-capture flow). Gated by both the resolved
 * {@see \Gomrok\Modules\Providers\Application\ProviderCapabilityResolver}
 * capability (Q5b — a client/country config can disable capture even where
 * the adapter supports it) and the adapter's actual
 * {@see SupportsAuthCapture} implementation.
 */
final readonly class CapturePaymentHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private ResolvePaymentActionContext $context,
        private RecordProviderTransactionHandler $recordTransaction,
    ) {
    }

    public function handle(CapturePaymentCommand $command): Result
    {
        $payment = $this->resolvePayment($command->clientId, $command->checkoutAttemptId, $command->paymentId);
        if ($payment === null) {
            return Result::err(DomainError::notFound('payment.not_found', $this->notFoundMessage($command->checkoutAttemptId, $command->paymentId)));
        }

        if ($payment->status() !== PaymentStatus::Authorized) {
            return Result::err(DomainError::validation(
                'payment.not_authorized',
                'Only an authorized payment can be captured.',
                ['status' => $payment->status()->value],
            ));
        }

        $context = $this->context->forPayment($payment);
        if ($context === null) {
            return Result::err(DomainError::validation('payment.provider_context_unavailable', 'The provider routing decision or gateway reference for this payment could not be resolved.'));
        }

        if (!$context->capabilities->supports(Capability::Capture) || !$context->adapter instanceof SupportsAuthCapture) {
            return Result::err(DomainError::unsupported('payment.capture_not_supported', 'The selected provider does not support capture for this client/country.'));
        }

        $paymentId = $payment->id();
        \assert($paymentId !== null);

        try {
            $result = $context->adapter->capturePayment($context->providerReference, $command->amountMinor);
        } catch (ProviderAdapterException $e) {
            return Result::err(DomainError::upstreamFailure('payment.capture_failed', $e->getMessage()));
        }

        $mappedStatus = $context->adapter->mapProviderStatusToInternalStatus($result->rawStatus);

        $recorded = $this->recordTransaction->handle(new RecordProviderTransactionCommand(
            clientId: $command->clientId,
            paymentId: $paymentId,
            providerAccountId: $context->providerAccountId,
            kind: 'capture',
            providerStatusRaw: $result->rawStatus,
            newStatus: $mappedStatus->value,
            requestPayload: ['amount_minor' => $command->amountMinor],
            responsePayload: ['provider_reference' => $result->providerReference, 'raw_status' => $result->rawStatus],
            attemptOutcome: 'succeeded',
            actorId: $command->actorId,
        ));
        if ($recorded->isErr()) {
            return $recorded;
        }

        $value = $recorded->value();
        \assert($value instanceof RecordProviderTransactionResult);

        return Result::ok(new CapturePaymentResult($paymentId, $value->paymentStatus, $result->providerReference));
    }

    private function resolvePayment(int $clientId, ?int $checkoutAttemptId, ?int $paymentId): ?Payment
    {
        $payment = $paymentId !== null
            ? $this->payments->findById($paymentId)
            : ($checkoutAttemptId !== null ? $this->payments->findByCheckoutAttemptId($checkoutAttemptId) : null);

        return $payment !== null && $payment->clientId() === $clientId ? $payment : null;
    }

    private function notFoundMessage(?int $checkoutAttemptId, ?int $paymentId): string
    {
        return $paymentId !== null
            ? "Payment {$paymentId} was not found."
            : "No payment was found for checkout attempt {$checkoutAttemptId}.";
    }
}
