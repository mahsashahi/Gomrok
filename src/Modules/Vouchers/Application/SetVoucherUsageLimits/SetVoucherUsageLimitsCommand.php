<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\SetVoucherUsageLimits;

/**
 * Set a voucher's three usage caps (Phase 16 Q3). Each is nullable — `null`
 * means unlimited for that dimension.
 */
final readonly class SetVoucherUsageLimitsCommand
{
    public function __construct(
        public int $clientId,
        public int $voucherId,
        public ?int $maxTotalRedemptions = null,
        public ?int $maxPerUser = null,
        public ?int $maxPerClient = null,
        public ?int $actorId = null,
    ) {
    }
}
