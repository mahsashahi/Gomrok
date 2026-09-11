<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\SetVoucherUsageLimits;

use Gomrok\Modules\Vouchers\Application\VoucherAuditSnapshot;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Sets a voucher's global / per-user / per-client usage caps. `null` = the
 * dimension is unlimited (Phase 16 Q3).
 */
final readonly class SetVoucherUsageLimitsHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetVoucherUsageLimitsCommand $command): Result
    {
        $voucher = $this->vouchers->findById($command->voucherId);
        if ($voucher === null || $voucher->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('voucher.not_found', "Voucher {$command->voucherId} was not found for this client."));
        }

        $before = VoucherAuditSnapshot::voucher($voucher);
        $error = $voucher->setUsageLimits($command->maxTotalRedemptions, $command->maxPerUser, $command->maxPerClient, $this->clock->now());
        if ($error !== null) {
            return Result::err($error);
        }

        $this->transactions->run(function () use ($voucher, $before, $command): void {
            $this->vouchers->save($voucher);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'voucher.usage_limits_set')
                : AuditEntry::forSystem('voucher.usage_limits_set', $command->clientId);

            $this->audit->record($entry->withTarget('voucher', $command->voucherId)->withChange($before, VoucherAuditSnapshot::voucher($voucher)));
        });

        return Result::ok(null);
    }
}
