<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\ReleaseVoucherRedemption;

use Gomrok\Modules\Vouchers\Application\VoucherAuditSnapshot;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemptionRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Frees a reservation after a failed / canceled / expired payment attempt:
 * `reserved -> released`. Idempotent — releasing an already-released
 * redemption is a no-op. A confirmed redemption can never be released (that
 * needs a refund flow, not this). Releasing simply stops the row from
 * counting toward the caps (Phase 17 Q2) — no counter to decrement.
 */
final readonly class ReleaseVoucherRedemptionHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private VoucherRedemptionRepository $redemptions,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(int $voucherId, string $attemptReference, int $clientId, ?int $actorId = null): Result
    {
        return $this->transactions->run(function () use ($voucherId, $attemptReference, $clientId, $actorId): Result {
            $voucher = $this->vouchers->findByIdForUpdate($voucherId);
            if ($voucher === null || $voucher->clientId() !== $clientId) {
                return Result::err(DomainError::notFound('voucher.not_found', "Voucher {$voucherId} was not found for this client."));
            }

            $redemption = $this->redemptions->findByAttemptReference($voucherId, $attemptReference);
            if ($redemption === null) {
                return Result::err(DomainError::notFound('voucher_redemption.not_found', 'No redemption was found for this attempt.'));
            }

            $before = VoucherAuditSnapshot::redemption($redemption);
            $error = $redemption->release($this->clock->now());
            if ($error !== null) {
                return Result::err($error);
            }

            $this->redemptions->save($redemption);
            $redemptionId = $redemption->id();
            \assert($redemptionId !== null);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, 'voucher_redemption.released')
                : AuditEntry::forSystem('voucher_redemption.released', $clientId);
            $this->audit->record($entry->withTarget('voucher_redemption', $redemptionId)->withChange($before, VoucherAuditSnapshot::redemption($redemption)));

            return Result::ok(null);
        });
    }
}
