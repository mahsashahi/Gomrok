<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\RemoveVoucherCurrencyDiscount;

use Gomrok\Modules\Vouchers\Application\VoucherAuditSnapshot;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscountRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use InvalidArgumentException;

/**
 * Removes a per-currency discount override, reverting that currency to the
 * voucher's default discount.
 */
final readonly class RemoveVoucherCurrencyDiscountHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private VoucherCurrencyDiscountRepository $discounts,
        private AuditLogWriter $audit,
        private Transactions $transactions,
    ) {
    }

    public function handle(int $voucherId, string $currencyCode, int $clientId, ?int $actorId = null): Result
    {
        $voucher = $this->vouchers->findById($voucherId);
        if ($voucher === null || $voucher->clientId() !== $clientId) {
            return Result::err(DomainError::notFound('voucher.not_found', "Voucher {$voucherId} was not found for this client."));
        }

        try {
            $currency = Currency::of($currencyCode)->code();
        } catch (InvalidArgumentException) {
            return Result::err(DomainError::validation('voucher_currency_discount.invalid_currency', "'{$currencyCode}' is not a valid ISO 4217 currency."));
        }

        $existing = $this->discounts->find($voucherId, $currency);
        if ($existing === null) {
            return Result::err(DomainError::notFound('voucher_currency_discount.not_found', "Voucher {$voucherId} has no override for '{$currency}'."));
        }

        $before = VoucherAuditSnapshot::currencyDiscount($existing);
        $this->transactions->run(function () use ($voucherId, $currency, $before, $clientId, $actorId): void {
            $this->discounts->delete($voucherId, $currency);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, 'voucher_currency_discount.removed')
                : AuditEntry::forSystem('voucher_currency_discount.removed', $clientId);

            $this->audit->record($entry->withTarget('voucher', $voucherId)->withChange($before, null));
        });

        return Result::ok(null);
    }
}
