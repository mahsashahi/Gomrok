<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ChangeCheckoutAttemptStatus;

use Gomrok\Modules\Checkout\Application\CheckoutAuditSnapshot;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * The escape hatch for every transition not driven by a dedicated handler
 * (Phase 18 Q5): the 4 exit statuses today, and `provider_checkout_created`
 * … `converted_to_payment` once later phases have real callers for them.
 * Guarded entirely by {@see \Gomrok\Modules\Checkout\Domain\CheckoutAttempt::transitionTo}.
 */
final readonly class ChangeCheckoutAttemptStatusHandler
{
    public function __construct(
        private CheckoutAttemptRepository $attempts,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(int $checkoutAttemptId, int $clientId, string $status, ?string $errorCode = null, ?string $errorMessage = null, ?int $actorId = null): Result
    {
        $new = CheckoutAttemptStatus::tryFrom($status);
        if ($new === null) {
            return Result::err(DomainError::validation('checkout_attempt.unknown_status', "Unknown checkout attempt status '{$status}'.", ['status' => $status]));
        }

        $attempt = $this->attempts->findById($checkoutAttemptId);
        if ($attempt === null || $attempt->clientId() !== $clientId) {
            return Result::err(DomainError::notFound('checkout_attempt.not_found', "Checkout attempt {$checkoutAttemptId} was not found for this client."));
        }

        $before = CheckoutAuditSnapshot::attempt($attempt);
        $error = $attempt->transitionTo($new, $this->clock->now(), $errorCode, $errorMessage);
        if ($error !== null) {
            return Result::err($error);
        }

        $this->transactions->run(function () use ($attempt, $before, $clientId, $checkoutAttemptId, $new, $actorId): void {
            $this->attempts->save($attempt);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, 'checkout_attempt.status_changed')
                : AuditEntry::forSystem('checkout_attempt.status_changed', $clientId);

            $this->audit->record(
                $entry->withTarget('checkout_attempt', $checkoutAttemptId)
                    ->withChange($before, CheckoutAuditSnapshot::attempt($attempt))
                    ->withContext(['new_status' => $new->value]),
            );
        });

        return Result::ok(null);
    }
}
