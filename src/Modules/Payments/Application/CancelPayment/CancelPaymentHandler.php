<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\CancelPayment;

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
 * `POST /api/v1/payments/{id}/cancel` (Phase 24) — only meaningful before a
 * payment has actually been captured/settled (`created`/`pending`/
 * `requires_action`/`authorized`); a paid payment is refunded, not
 * cancelled. `cancelPayment()` returns no raw provider status ({@see
 * SupportsAuthCapture}'s contract is `void`), so `provider_transactions`
 * records a fixed Gomrok-side label rather than an unmapped provider string.
 */
final readonly class CancelPaymentHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private ResolvePaymentActionContext $context,
        private RecordProviderTransactionHandler $recordTransaction,
    ) {
    }

    public function handle(CancelPaymentCommand $command): Result
    {
        $payment = $this->resolvePayment($command->clientId, $command->checkoutAttemptId, $command->paymentId);
        if ($payment === null) {
            return Result::err(DomainError::notFound('payment.not_found', $this->notFoundMessage($command->checkoutAttemptId, $command->paymentId)));
        }

        $cancelableStatuses = [PaymentStatus::Created, PaymentStatus::Pending, PaymentStatus::RequiresAction, PaymentStatus::Authorized];
        if (!\in_array($payment->status(), $cancelableStatuses, true)) {
            return Result::err(DomainError::validation(
                'payment.not_cancelable',
                'Only a payment that has not yet been captured can be cancelled.',
                ['status' => $payment->status()->value],
            ));
        }

        $context = $this->context->forPayment($payment);
        if ($context === null) {
            return Result::err(DomainError::validation('payment.provider_context_unavailable', 'The provider routing decision or gateway reference for this payment could not be resolved.'));
        }

        if (!$context->capabilities->supports(Capability::Cancel) || !$context->adapter instanceof SupportsAuthCapture) {
            return Result::err(DomainError::unsupported('payment.cancel_not_supported', 'The selected provider does not support cancelling this payment for this client/country.'));
        }

        $paymentId = $payment->id();
        \assert($paymentId !== null);

        try {
            $context->adapter->cancelPayment($context->providerReference);
        } catch (ProviderAdapterException $e) {
            return Result::err(DomainError::upstreamFailure('payment.cancel_failed', $e->getMessage()));
        }

        $recorded = $this->recordTransaction->handle(new RecordProviderTransactionCommand(
            clientId: $command->clientId,
            paymentId: $paymentId,
            providerAccountId: $context->providerAccountId,
            kind: 'void',
            providerStatusRaw: 'void_requested',
            newStatus: PaymentStatus::Canceled->value,
            attemptOutcome: 'succeeded',
            actorId: $command->actorId,
        ));
        if ($recorded->isErr()) {
            return $recorded;
        }

        $value = $recorded->value();
        \assert($value instanceof RecordProviderTransactionResult);

        return Result::ok(new CancelPaymentResult($paymentId, $value->paymentStatus));
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
