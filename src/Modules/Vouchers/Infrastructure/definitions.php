<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Vouchers\Application\VoucherDirectory;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscountRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRuleRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherCurrencyDiscountRepository;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherDirectory;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherEligibilityRuleRepository;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherRepository;

/**
 * PHP-DI definitions for the Vouchers module (Phase 16 — definitions &
 * eligibility). `VoucherEligibilityEvaluator` and the use-case handlers are
 * autowired.
 *
 * @return array<string, mixed>
 */
return [
    VoucherRepository::class => get(PdoVoucherRepository::class),
    VoucherEligibilityRuleRepository::class => get(PdoVoucherEligibilityRuleRepository::class),
    VoucherCurrencyDiscountRepository::class => get(PdoVoucherCurrencyDiscountRepository::class),
    VoucherDirectory::class => get(PdoVoucherDirectory::class),
];
