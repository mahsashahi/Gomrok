<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\SetVoucherCurrencyDiscount;

use Gomrok\Modules\Vouchers\Application\VoucherAuditSnapshot;
use Gomrok\Modules\Vouchers\Domain\DiscountType;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscount;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscountRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use InvalidArgumentException;

/**
 * Upserts a per-currency discount override, keyed by `(voucher, currency)`.
 */
final readonly class SetVoucherCurrencyDiscountHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private VoucherCurrencyDiscountRepository $discounts,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
    ) {
    }

    public function handle(SetVoucherCurrencyDiscountCommand $command): Result
    {
        $voucher = $this->vouchers->findById($command->voucherId);
        if ($voucher === null || $voucher->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('voucher.not_found', "Voucher {$command->voucherId} was not found for this client."));
        }

        try {
            $currency = Currency::of($command->currencyCode)->code();
        } catch (InvalidArgumentException) {
            return Result::err(DomainError::validation('voucher_currency_discount.invalid_currency', "'{$command->currencyCode}' is not a valid ISO 4217 currency."));
        }
        if (!$this->reference->currencyExists($currency)) {
            return Result::err(DomainError::validation('voucher_currency_discount.unknown_currency', "Currency '{$currency}' is not configured.", ['currency' => $currency]));
        }

        $type = DiscountType::tryFrom($command->discountType);
        if ($type === null) {
            return Result::err(DomainError::validation('voucher_currency_discount.unknown_type', "Unknown discount type '{$command->discountType}'.", ['type' => $command->discountType]));
        }
        $error = VoucherCurrencyDiscount::validate($type, $command->percentBp, $command->amountMinor, $command->maxDiscountMinor);
        if ($error !== null) {
            return Result::err($error);
        }

        $existing = $this->discounts->find($command->voucherId, $currency);
        $before = $existing !== null ? VoucherAuditSnapshot::currencyDiscount($existing) : null;
        $created = $existing === null;
        $discount = new VoucherCurrencyDiscount($command->voucherId, $currency, $type, $command->percentBp, $command->amountMinor, $command->maxDiscountMinor);

        $this->transactions->run(function () use ($discount, $before, $command): void {
            $this->discounts->save($discount);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'voucher_currency_discount.set')
                : AuditEntry::forSystem('voucher_currency_discount.set', $command->clientId);

            $this->audit->record(
                $entry->withTarget('voucher', $command->voucherId)
                    ->withChange($before, VoucherAuditSnapshot::currencyDiscount($discount)),
            );
        });

        return Result::ok(new SetVoucherCurrencyDiscountResult($created));
    }
}
