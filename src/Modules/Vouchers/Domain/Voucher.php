<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

use DateTimeImmutable;
use Gomrok\Shared\Domain\DomainError;

/**
 * A client-scoped discount code (Phase 16). `id` is null until persisted.
 * `redeemedCount` is a Phase-17-owned tally — this module only reads it
 * (for the global usage-cap eligibility check).
 */
final class Voucher
{
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly string $code,
        private string $name,
        private ?string $description,
        private VoucherStatus $status,
        private ?DateTimeImmutable $validFrom,
        private ?DateTimeImmutable $validUntil,
        private bool $firstPurchaseOnly,
        private ?int $minPurchaseMinor,
        private ?string $minPurchaseCurrency,
        private DefaultDiscountType $defaultDiscountType,
        private ?int $defaultPercentBp,
        private ?int $maxTotalRedemptions,
        private ?int $maxPerUser,
        private ?int $maxPerClient,
        private readonly int $redeemedCount,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        int $clientId,
        string $code,
        string $name,
        ?string $description,
        ?DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
        bool $firstPurchaseOnly,
        ?int $minPurchaseMinor,
        ?string $minPurchaseCurrency,
        DefaultDiscountType $defaultDiscountType,
        ?int $defaultPercentBp,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            $clientId,
            strtoupper(trim($code)),
            trim($name),
            self::trimToNull($description),
            VoucherStatus::Active,
            $validFrom,
            $validUntil,
            $firstPurchaseOnly,
            $minPurchaseMinor,
            $minPurchaseCurrency !== null ? strtoupper($minPurchaseCurrency) : null,
            $defaultDiscountType,
            $defaultPercentBp,
            null,
            null,
            null,
            0,
            $now,
            null,
        );
    }

    public static function fromStorage(
        int $id,
        int $clientId,
        string $code,
        string $name,
        ?string $description,
        VoucherStatus $status,
        ?DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
        bool $firstPurchaseOnly,
        ?int $minPurchaseMinor,
        ?string $minPurchaseCurrency,
        DefaultDiscountType $defaultDiscountType,
        ?int $defaultPercentBp,
        ?int $maxTotalRedemptions,
        ?int $maxPerUser,
        ?int $maxPerClient,
        int $redeemedCount,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $clientId,
            $code,
            $name,
            $description,
            $status,
            $validFrom,
            $validUntil,
            $firstPurchaseOnly,
            $minPurchaseMinor,
            $minPurchaseCurrency,
            $defaultDiscountType,
            $defaultPercentBp,
            $maxTotalRedemptions,
            $maxPerUser,
            $maxPerClient,
            $redeemedCount,
            $createdAt,
            $updatedAt,
        );
    }

    /**
     * Structural consistency for the default discount (Phase 16 Q2).
     */
    public static function validateDefaultDiscount(DefaultDiscountType $type, ?int $percentBp): ?DomainError
    {
        return match ($type) {
            DefaultDiscountType::Percentage => $percentBp === null || $percentBp < 1 || $percentBp > 10000
                ? DomainError::validation('voucher.percent_out_of_range', 'The default percentage must be between 0.01% and 100% (1..10000 basis points).')
                : null,
            DefaultDiscountType::None, DefaultDiscountType::Full => $percentBp !== null
                ? DomainError::validation('voucher.unexpected_percent', "A default of '{$type->value}' cannot carry a percentage.")
                : null,
        };
    }

    /**
     * Structural consistency for the minimum-purchase guard.
     */
    public static function validateMinPurchase(?int $minPurchaseMinor, ?string $minPurchaseCurrency): ?DomainError
    {
        if (($minPurchaseMinor === null) !== ($minPurchaseCurrency === null)) {
            return DomainError::validation('voucher.min_purchase_incomplete', 'A minimum purchase needs both an amount and a currency.');
        }
        if ($minPurchaseMinor !== null && $minPurchaseMinor <= 0) {
            return DomainError::validation('voucher.min_purchase_non_positive', 'The minimum purchase amount must be greater than zero.');
        }

        return null;
    }

    /**
     * Structural consistency for the validity window.
     */
    public static function validateWindow(?DateTimeImmutable $validFrom, ?DateTimeImmutable $validUntil): ?DomainError
    {
        if ($validFrom !== null && $validUntil !== null && $validFrom > $validUntil) {
            return DomainError::validation('voucher.invalid_window', '"valid_from" must not be after "valid_until".');
        }

        return null;
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function update(
        string $name,
        ?string $description,
        ?DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
        bool $firstPurchaseOnly,
        ?int $minPurchaseMinor,
        ?string $minPurchaseCurrency,
        DefaultDiscountType $defaultDiscountType,
        ?int $defaultPercentBp,
        DateTimeImmutable $now,
    ): void {
        $this->name = trim($name);
        $this->description = self::trimToNull($description);
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $this->firstPurchaseOnly = $firstPurchaseOnly;
        $this->minPurchaseMinor = $minPurchaseMinor;
        $this->minPurchaseCurrency = $minPurchaseCurrency !== null ? strtoupper($minPurchaseCurrency) : null;
        $this->defaultDiscountType = $defaultDiscountType;
        $this->defaultPercentBp = $defaultPercentBp;
        $this->updatedAt = $now;
    }

    public function setUsageLimits(?int $maxTotalRedemptions, ?int $maxPerUser, ?int $maxPerClient, DateTimeImmutable $now): ?DomainError
    {
        foreach (['max_total_redemptions' => $maxTotalRedemptions, 'max_per_user' => $maxPerUser, 'max_per_client' => $maxPerClient] as $field => $value) {
            if ($value !== null && $value < 1) {
                return DomainError::validation('voucher.invalid_limit', "'{$field}' must be null (unlimited) or at least 1.", ['field' => $field]);
            }
        }
        $this->maxTotalRedemptions = $maxTotalRedemptions;
        $this->maxPerUser = $maxPerUser;
        $this->maxPerClient = $maxPerClient;
        $this->updatedAt = $now;

        return null;
    }

    public function enable(DateTimeImmutable $now): void
    {
        if ($this->status !== VoucherStatus::Active) {
            $this->status = VoucherStatus::Active;
            $this->updatedAt = $now;
        }
    }

    public function disable(DateTimeImmutable $now): void
    {
        if ($this->status !== VoucherStatus::Disabled) {
            $this->status = VoucherStatus::Disabled;
            $this->updatedAt = $now;
        }
    }

    public function isActive(): bool
    {
        return $this->status === VoucherStatus::Active;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function status(): VoucherStatus
    {
        return $this->status;
    }

    public function validFrom(): ?DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function validUntil(): ?DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function firstPurchaseOnly(): bool
    {
        return $this->firstPurchaseOnly;
    }

    public function minPurchaseMinor(): ?int
    {
        return $this->minPurchaseMinor;
    }

    public function minPurchaseCurrency(): ?string
    {
        return $this->minPurchaseCurrency;
    }

    public function defaultDiscountType(): DefaultDiscountType
    {
        return $this->defaultDiscountType;
    }

    public function defaultPercentBp(): ?int
    {
        return $this->defaultPercentBp;
    }

    public function maxTotalRedemptions(): ?int
    {
        return $this->maxTotalRedemptions;
    }

    public function maxPerUser(): ?int
    {
        return $this->maxPerUser;
    }

    public function maxPerClient(): ?int
    {
        return $this->maxPerClient;
    }

    public function redeemedCount(): int
    {
        return $this->redeemedCount;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
