<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\ConfirmVoucherRedemption;

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
 * Finalises a reservation after a successful payment: `reserved -> confirmed`,
 * incrementing the voucher's global `redeemed_count`. Idempotent — confirming
 * an already-confirmed redemption is a no-op and does not double-increment.
 * A released redemption can never be confirmed.
 */
final readonly class ConfirmVoucherRedemptionHandler
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

            $wasReserved = $redemption->isReserved();
            $before = VoucherAuditSnapshot::redemption($redemption);
            $error = $redemption->confirm($this->clock->now());
            if ($error !== null) {
                return Result::err($error);
            }

            $this->redemptions->save($redemption);
            if ($wasReserved) {
                $this->vouchers->incrementRedeemedCount($voucherId);
            }

            $redemptionId = $redemption->id();
            \assert($redemptionId !== null);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, 'voucher_redemption.confirmed')
                : AuditEntry::forSystem('voucher_redemption.confirmed', $clientId);
            $this->audit->record($entry->withTarget('voucher_redemption', $redemptionId)->withChange($before, VoucherAuditSnapshot::redemption($redemption)));

            return Result::ok(null);
        });
    }
}
