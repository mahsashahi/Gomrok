<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\ChangePaymentStatus;

use Gomrok\Modules\Payments\Application\PaymentAuditSnapshot;
use Gomrok\Modules\Payments\Domain\PaymentRepository;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * The escape hatch for any transition not driven by
 * {@see \Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler}
 * — e.g. an admin manually canceling a payment, or marking it `disputed` on a
 * chargeback notice with no other provider data attached. Guarded entirely by
 * {@see \Gomrok\Modules\Payments\Domain\Payment::transitionTo}.
 */
final readonly class ChangePaymentStatusHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(int $paymentId, int $clientId, string $status, ?string $errorCode = null, ?string $errorMessage = null, ?int $actorId = null): Result
    {
        $new = PaymentStatus::tryFrom($status);
        if ($new === null) {
            return Result::err(DomainError::validation('payment.unknown_status', "Unknown payment status '{$status}'.", ['status' => $status]));
        }

        $payment = $this->payments->findById($paymentId);
        if ($payment === null || $payment->clientId() !== $clientId) {
            return Result::err(DomainError::notFound('payment.not_found', "Payment {$paymentId} was not found for this client."));
        }

        $before = PaymentAuditSnapshot::payment($payment);
        $error = $payment->transitionTo($new, $this->clock->now(), $errorCode, $errorMessage);
        if ($error !== null) {
            return Result::err($error);
        }

        $this->transactions->run(function () use ($payment, $before, $clientId, $paymentId, $new, $actorId): void {
            $this->payments->save($payment);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, 'payment.status_changed')
                : AuditEntry::forSystem('payment.status_changed', $clientId);

            $this->audit->record(
                $entry->withTarget('payment', $paymentId)
                    ->withChange($before, PaymentAuditSnapshot::payment($payment))
                    ->withContext(['new_status' => $new->value]),
            );
        });

        return Result::ok(null);
    }
}
