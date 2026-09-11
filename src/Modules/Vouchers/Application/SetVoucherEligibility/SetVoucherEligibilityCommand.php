<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\SetVoucherEligibility;

/**
 * Full-replace the eligibility rule set for a voucher (Phase 16 Q1 / Q5).
 */
final readonly class SetVoucherEligibilityCommand
{
    /**
     * @param list<array{dimension: string, value: string}> $rules
     */
    public function __construct(
        public int $clientId,
        public int $voucherId,
        public array $rules,
        public ?int $actorId = null,
    ) {
    }
}
