<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\RefundPayment;

use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionCommand;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionResult;
use Gomrok\Modules\Payments\Application\ResolvePaymentActionContext;
use Gomrok\Modules\Payments\Domain\PaymentRepository;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\SupportsRefunds;
use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;

/**
 * `POST /api/v1/payments/{id}/refund` (Phase 24). `amountMinor === null`
 * means "refund the full remaining amount" ({@see SupportsRefunds}'s own
 * convention). Whether the resulting status is `refunded` or
 * `partially_refunded` is decided here from the requested amount versus the
 * payment's own frozen `amountMinor` — no adapter maps a provider's raw
 * status into those two `PaymentStatus` cases (Phase 21's mappers only cover
 * the creation-time vocabulary).
 */
final readonly class RefundPaymentHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private ResolvePaymentActionContext $context,
        private RecordProviderTransactionHandler $recordTransaction,
    ) {
    }

    public function handle(RefundPaymentCommand $command): Result
    {
        $payment = $this->payments->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($payment === null || $payment->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('payment.not_found', "No payment was found for checkout attempt {$command->checkoutAttemptId}."));
        }

        if (!\in_array($payment->status(), [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            return Result::err(DomainError::validation(
                'payment.not_refundable',
                'Only a paid or partially refunded payment can be refunded.',
                ['status' => $payment->status()->value],
            ));
        }

        if ($command->amountMinor !== null && $command->amountMinor > $payment->amountMinor()) {
            return Result::err(DomainError::validation(
                'payment.refund_amount_exceeds_payment',
                'The refund amount cannot exceed the payment amount.',
                ['amount_minor' => $command->amountMinor, 'payment_amount_minor' => $payment->amountMinor()],
            ));
        }

        $context = $this->context->forPayment($payment);
        if ($context === null) {
            return Result::err(DomainError::validation('payment.provider_context_unavailable', 'The provider routing decision or gateway reference for this payment could not be resolved.'));
        }

        if (!$context->capabilities->supports(Capability::Refund) || !$context->adapter instanceof SupportsRefunds) {
            return Result::err(DomainError::unsupported('payment.refund_not_supported', 'The selected provider does not support refunds for this client/country.'));
        }

        $isFullRefund = $command->amountMinor === null || $command->amountMinor === $payment->amountMinor();
        if (!$isFullRefund && !$context->capabilities->supports(Capability::PartialRefund)) {
            return Result::err(DomainError::unsupported('payment.partial_refund_not_supported', 'The selected provider only supports refunding the full amount.'));
        }

        $paymentId = $payment->id();
        \assert($paymentId !== null);

        try {
            $result = $context->adapter->refundPayment($context->providerReference, $command->amountMinor);
        } catch (ProviderAdapterException $e) {
            return Result::err(DomainError::upstreamFailure('payment.refund_failed', $e->getMessage()));
        }

        $newStatus = $isFullRefund ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;

        $recorded = $this->recordTransaction->handle(new RecordProviderTransactionCommand(
            clientId: $command->clientId,
            paymentId: $paymentId,
            providerAccountId: $context->providerAccountId,
            kind: 'refund',
            providerStatusRaw: $result->rawStatus,
            newStatus: $newStatus->value,
            requestPayload: ['amount_minor' => $command->amountMinor],
            responsePayload: ['provider_reference' => $result->providerReference, 'refunded_minor' => $result->amountMinor, 'raw_status' => $result->rawStatus],
            attemptOutcome: 'succeeded',
            actorId: $command->actorId,
        ));
        if ($recorded->isErr()) {
            return $recorded;
        }

        $value = $recorded->value();
        \assert($value instanceof RecordProviderTransactionResult);

        return Result::ok(new RefundPaymentResult($paymentId, $value->paymentStatus, $result->providerReference, $result->amountMinor));
    }
}
