<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\CreateVoucher;

use DateTimeImmutable;
use Exception;
use Gomrok\Modules\Clients\Application\ClientDirectory;
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
 * Creates a voucher: validates the client, the code format + uniqueness, the
 * validity window, the minimum purchase, and the default discount.
 */
final readonly class CreateVoucherHandler
{
    private const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{2,63}$/';

    public function __construct(
        private VoucherRepository $vouchers,
        private ClientDirectory $clients,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CreateVoucherCommand $command): Result
    {
        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }
        if (!$client->isActive()) {
            return Result::err(DomainError::forbidden('client.disabled', 'Cannot add a voucher to a disabled client.'));
        }

        $code = strtoupper(trim($command->code));
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            return Result::err(DomainError::validation('voucher.invalid_code', "'{$command->code}' must be 3-64 characters of A-Z, 0-9, '_' or '-'.", ['code' => $command->code]));
        }

        $name = trim($command->name);
        if ($name === '') {
            return Result::err(DomainError::validation('voucher.name_required', 'A name is required.'));
        }

        if ($this->vouchers->existsForClientWithCode($command->clientId, $code)) {
            return Result::err(DomainError::conflict('voucher.code_taken', "This client already has a voucher '{$code}'.", ['code' => $code]));
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

        $now = $this->clock->now();
        $voucher = Voucher::create(
            $command->clientId,
            $code,
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

        $this->transactions->run(function () use ($voucher, $command): void {
            $this->vouchers->save($voucher);
            $voucherId = $voucher->id();
            \assert($voucherId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'voucher.created')
                : AuditEntry::forSystem('voucher.created', $command->clientId);

            $this->audit->record($entry->withTarget('voucher', $voucherId)->withChange(null, VoucherAuditSnapshot::voucher($voucher)));
        });

        $voucherId = $voucher->id();
        \assert($voucherId !== null);

        return Result::ok(new CreateVoucherResult($voucherId));
    }
}
