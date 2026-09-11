<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\ChangeVoucherStatus;

use Gomrok\Modules\Vouchers\Application\VoucherAuditSnapshot;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Enable / disable a voucher (soft, reversible, idempotent).
 */
final readonly class ChangeVoucherStatusHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function enable(int $voucherId, int $clientId, ?int $actorId = null): Result
    {
        return $this->apply($voucherId, $clientId, true, $actorId);
    }

    public function disable(int $voucherId, int $clientId, ?int $actorId = null): Result
    {
        return $this->apply($voucherId, $clientId, false, $actorId);
    }

    private function apply(int $voucherId, int $clientId, bool $enable, ?int $actorId): Result
    {
        $voucher = $this->vouchers->findById($voucherId);
        if ($voucher === null || $voucher->clientId() !== $clientId) {
            return Result::err(DomainError::notFound('voucher.not_found', "Voucher {$voucherId} was not found for this client."));
        }

        if ($voucher->isActive() === $enable) {
            return Result::ok(null);
        }

        $before = VoucherAuditSnapshot::voucher($voucher);
        $now = $this->clock->now();
        if ($enable) {
            $voucher->enable($now);
        } else {
            $voucher->disable($now);
        }

        $action = $enable ? 'voucher.enabled' : 'voucher.disabled';
        $this->transactions->run(function () use ($voucher, $before, $clientId, $voucherId, $action, $actorId): void {
            $this->vouchers->save($voucher);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, $action)
                : AuditEntry::forSystem($action, $clientId);

            $this->audit->record($entry->withTarget('voucher', $voucherId)->withChange($before, VoucherAuditSnapshot::voucher($voucher)));
        });

        return Result::ok(null);
    }
}
