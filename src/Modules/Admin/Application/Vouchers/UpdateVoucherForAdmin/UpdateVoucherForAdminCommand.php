<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Vouchers\UpdateVoucherForAdmin;

/**
 * The Vouchers screen's "Edit voucher" modal (Phase 27) — composes
 * {@see \Gomrok\Modules\Vouchers\Application\UpdateVoucher\UpdateVoucherHandler}
 * and {@see \Gomrok\Modules\Vouchers\Application\ChangeVoucherStatus\ChangeVoucherStatusHandler},
 * since the voucher's descriptive fields and its status are separate use cases
 * but one modal. Eligibility rules, usage caps and per-currency overrides have
 * their own modals and are not part of this command.
 */
final readonly class UpdateVoucherForAdminCommand
{
    public function __construct(
        public int $clientId,
        public int $voucherId,
        public string $name,
        public ?string $description,
        public ?string $validFrom,
        public ?string $validUntil,
        public bool $firstPurchaseOnly,
        public ?int $minPurchaseMinor,
        public ?string $minPurchaseCurrency,
        public string $defaultDiscountType,
        public ?int $defaultPercentBp,
        public bool $active,
        public ?int $actorId = null,
    ) {
    }
}
