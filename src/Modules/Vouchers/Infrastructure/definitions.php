<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Vouchers\Application\VoucherDirectory;
use Gomrok\Modules\Vouchers\Application\VoucherRedemptionDirectory;
use Gomrok\Modules\Vouchers\Application\VoucherUsagePort;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscountRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherDecisionSnapshotRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRuleRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemptionRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherCurrencyDiscountRepository;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherDecisionSnapshotRepository;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherDirectory;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherEligibilityRuleRepository;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherRedemptionDirectory;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherRedemptionRepository;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherRepository;

/**
 * PHP-DI definitions for the Vouchers module (Phase 16 — definitions &
 * eligibility; Phase 17 — discount calc & redemption lifecycle).
 * `VoucherEligibilityEvaluator` / `VoucherDiscountCalculator` and the use-case
 * handlers are autowired.
 *
 * @return array<string, mixed>
 */
return [
    VoucherRepository::class => get(PdoVoucherRepository::class),
    VoucherEligibilityRuleRepository::class => get(PdoVoucherEligibilityRuleRepository::class),
    VoucherCurrencyDiscountRepository::class => get(PdoVoucherCurrencyDiscountRepository::class),
    VoucherDirectory::class => get(PdoVoucherDirectory::class),
    VoucherRedemptionRepository::class => get(PdoVoucherRedemptionRepository::class),
    VoucherUsagePort::class => get(PdoVoucherRedemptionRepository::class),
    VoucherRedemptionDirectory::class => get(PdoVoucherRedemptionDirectory::class),
    VoucherDecisionSnapshotRepository::class => get(PdoVoucherDecisionSnapshotRepository::class),
];
