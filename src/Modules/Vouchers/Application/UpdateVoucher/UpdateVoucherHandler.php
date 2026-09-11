<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\UpdateVoucher;

use DateTimeImmutable;
use Exception;
use Gomrok\Modules\Vouchers\Application\VoucherAuditSnapshot;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Updates a voucher's descriptive fields, window, first-purchase flag, minimum
 * purchase, and default discount. Scoped to the client; `code` is immutable.
 */
final readonly class UpdateVoucherHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(UpdateVoucherCommand $command): Result
    {
        $voucher = $this->vouchers->findById($command->voucherId);
        if ($voucher === null || $voucher->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('voucher.not_found', "Voucher {$command->voucherId} was not found for this client."));
        }

        $name = trim($command->name);
        if ($name === '') {
            return Result::err(DomainError::validation('voucher.name_required', 'A name is required.'));
        }

        try {
            $validFrom = $command->validFrom !== null ? new DateTimeImmutable($command->validFrom) : null;
            $validUntil = $command->validUntil !== null ? new DateTimeImmutable($command->validUntil) : null;
        } catch (Exception) {
            return Result::err(DomainError::validation('voucher.invalid_datetime', 'The validity window contains an invalid datetime.'));
        }
        $windowError = Voucher::validateWindow($validFrom, $validUntil);
        if ($windowError !== null) {
            return Result::err($windowError);
        }

        if ($command->minPurchaseCurrency !== null) {
            try {
                $minCurrency = Currency::of($command->minPurchaseCurrency)->code();
            } catch (InvalidArgumentException) {
                return Result::err(DomainError::validation('voucher.invalid_currency', "'{$command->minPurchaseCurrency}' is not a valid ISO 4217 currency."));
            }
            if (!$this->reference->currencyExists($minCurrency)) {
                return Result::err(DomainError::validation('voucher.unknown_currency', "Currency '{$minCurrency}' is not configured.", ['currency' => $minCurrency]));
            }
        } else {
            $minCurrency = null;
        }
        $minPurchaseError = Voucher::validateMinPurchase($command->minPurchaseMinor, $minCurrency);
        if ($minPurchaseError !== null) {
            return Result::err($minPurchaseError);
        }

        $discountType = DefaultDiscountType::tryFrom($command->defaultDiscountType);
        if ($discountType === null) {
            return Result::err(DomainError::validation('voucher.unknown_discount_type', "Unknown default discount type '{$command->defaultDiscountType}'.", ['type' => $command->defaultDiscountType]));
        }
        $discountError = Voucher::validateDefaultDiscount($discountType, $command->defaultPercentBp);
        if ($discountError !== null) {
            return Result::err($discountError);
        }

        $before = VoucherAuditSnapshot::voucher($voucher);
        $now = $this->clock->now();
        $voucher->update(
            $name,
            $command->description,
            $validFrom,
            $validUntil,
            $command->firstPurchaseOnly,
            $command->minPurchaseMinor,
            $minCurrency,
            $discountType,
            $command->defaultPercentBp,
            $now,
        );

        $this->transactions->run(function () use ($voucher, $before, $command): void {
            $this->vouchers->save($voucher);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'voucher.updated')
                : AuditEntry::forSystem('voucher.updated', $command->clientId);

            $this->audit->record($entry->withTarget('voucher', $command->voucherId)->withChange($before, VoucherAuditSnapshot::voucher($voucher)));
        });

        return Result::ok(null);
    }
}
