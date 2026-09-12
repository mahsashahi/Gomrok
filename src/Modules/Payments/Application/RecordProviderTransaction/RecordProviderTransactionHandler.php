<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\RecordProviderTransaction;

use Gomrok\Modules\Payments\Application\PaymentAuditSnapshot;
use Gomrok\Modules\Payments\Domain\PaymentAttempt;
use Gomrok\Modules\Payments\Domain\PaymentAttemptRepository;
use Gomrok\Modules\Payments\Domain\PaymentAttemptStatus;
use Gomrok\Modules\Payments\Domain\PaymentRepository;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Payments\Domain\ProviderTransaction;
use Gomrok\Modules\Payments\Domain\ProviderTransactionRepository;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Records one raw provider call/response (Phase 20 Q3/Q5) — reuses the
 * payment's latest attempt if it's still `started`, else opens a new one
 * (`attempt_number` incrementing), appends an immutable
 * {@see ProviderTransaction} under it, optionally completes the attempt
 * (`succeeded`/`failed`, explicit — never inferred from the new payment
 * status), and transitions the payment per {@see PaymentStatus}'s allowed
 * transitions. There is no real provider adapter yet (Phase 21+); the caller
 * supplies `newStatus` directly.
 */
final readonly class RecordProviderTransactionHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentAttemptRepository $attempts,
        private ProviderTransactionRepository $providerTransactions,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(RecordProviderTransactionCommand $command): Result
    {
        $payment = $this->payments->findById($command->paymentId);
        if ($payment === null || $payment->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('payment.not_found', "Payment {$command->paymentId} was not found for this client."));
        }

        $newStatus = PaymentStatus::tryFrom($command->newStatus);
        if ($newStatus === null) {
            return Result::err(DomainError::validation('payment.unknown_status', "Unknown payment status '{$command->newStatus}'.", ['status' => $command->newStatus]));
        }

        if ($command->attemptOutcome !== null && !\in_array($command->attemptOutcome, ['succeeded', 'failed'], true)) {
            return Result::err(DomainError::validation('payment.unknown_attempt_outcome', "Unknown attempt outcome '{$command->attemptOutcome}'.", ['attempt_outcome' => $command->attemptOutcome]));
        }

        $method = null;
        if ($command->paymentMethod !== null) {
            $method = PaymentMethod::tryFrom($command->paymentMethod);
            if ($method === null) {
                return Result::err(DomainError::validation('payment.unknown_method', "Unknown payment method '{$command->paymentMethod}'.", ['method' => $command->paymentMethod]));
            }
        }

        $before = PaymentAuditSnapshot::payment($payment);
        $error = $payment->transitionTo($newStatus, $this->clock->now(), $command->errorCode, $command->errorMessage);
        if ($error !== null) {
            return Result::err($error);
        }

        $result = $this->transactions->run(function () use ($payment, $before, $command, $method): RecordProviderTransactionResult {
            $now = $this->clock->now();

            $attempt = $this->attempts->findLatestForPayment($command->paymentId);
            if ($attempt === null || $attempt->status() !== PaymentAttemptStatus::Started) {
                $attemptNumber = $this->attempts->countForPayment($command->paymentId) + 1;
                $attempt = PaymentAttempt::start($command->paymentId, $command->providerAccountId, $attemptNumber, $method, $now);
                $this->attempts->save($attempt);
            }

            $attemptId = $attempt->id();
            \assert($attemptId !== null);

            $transaction = ProviderTransaction::record($attemptId, $command->kind, $command->requestPayload, $command->responsePayload, $command->providerStatusRaw, $now);
            $transactionId = $this->providerTransactions->save($transaction);

            if ($command->attemptOutcome === 'succeeded') {
                $attempt->succeed($now);
                $this->attempts->save($attempt);
            } elseif ($command->attemptOutcome === 'failed') {
                $attempt->fail($now, $command->errorCode, $command->errorMessage);
                $this->attempts->save($attempt);
            }

            $this->payments->save($payment);

            $paymentId = $payment->id();
            \assert($paymentId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'payment.provider_transaction_recorded')
                : AuditEntry::forSystem('payment.provider_transaction_recorded', $command->clientId);
            $this->audit->record(
                $entry->withTarget('payment', $paymentId)
                    ->withChange($before, PaymentAuditSnapshot::payment($payment))
                    ->withContext(['kind' => $command->kind, 'provider_status_raw' => $command->providerStatusRaw]),
            );

            return new RecordProviderTransactionResult($attemptId, $transactionId, $payment->status()->value);
        });

        return Result::ok($result);
    }
}
