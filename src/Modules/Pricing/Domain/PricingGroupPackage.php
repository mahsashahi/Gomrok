<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

use DateTimeImmutable;
use Gomrok\Shared\Domain\DomainError;

/**
 * How one package is priced / displayed inside one pricing group (Phase 13 Q3).
 * `id` is null until persisted. A package with no row is treated as an implicit
 * `Default` at the end of the display order.
 */
final class PricingGroupPackage
{
    private function __construct(
        private ?int $id,
        private readonly int $pricingGroupId,
        private readonly int $packageId,
        private PricingRowStatus $status,
        private ?int $amountMinor,
        private ?string $currencyCode,
        private ?string $nameOverride,
        private ?string $badgeOverride,
        private ?bool $highlightedOverride,
        private int $displayOrder,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        int $pricingGroupId,
        int $packageId,
        PricingRowStatus $status,
        ?int $amountMinor,
        ?string $currencyCode,
        ?string $nameOverride,
        ?string $badgeOverride,
        ?bool $highlightedOverride,
        int $displayOrder,
        DateTimeImmutable $now,
    ): self {
        [$amount, $currency] = $status === PricingRowStatus::Override
            ? [$amountMinor, $currencyCode !== null ? strtoupper($currencyCode) : null]
            : [null, null];

        return new self(
            null,
            $pricingGroupId,
            $packageId,
            $status,
            $amount,
            $currency,
            self::trimToNull($nameOverride),
            self::trimToNull($badgeOverride),
            $highlightedOverride,
            max(0, $displayOrder),
            $now,
            null,
        );
    }

    public static function fromStorage(
        int $id,
        int $pricingGroupId,
        int $packageId,
        PricingRowStatus $status,
        ?int $amountMinor,
        ?string $currencyCode,
        ?string $nameOverride,
        ?string $badgeOverride,
        ?bool $highlightedOverride,
        int $displayOrder,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $pricingGroupId,
            $packageId,
            $status,
            $amountMinor,
            $currencyCode,
            $nameOverride,
            $badgeOverride,
            $highlightedOverride,
            $displayOrder,
            $createdAt,
            $updatedAt,
        );
    }

    public static function validate(
        PricingRowStatus $status,
        ?int $amountMinor,
        ?string $currencyCode,
        string $groupCurrency,
    ): ?DomainError {
        if ($status === PricingRowStatus::Override) {
            if ($amountMinor === null || $amountMinor < 0 || $currencyCode === null) {
                return DomainError::validation(
                    'pricing_group_package.override_amount_required',
                    'An override needs a non-negative amount and a currency.',
                );
            }
            if (strtoupper($currencyCode) !== strtoupper($groupCurrency)) {
                return DomainError::validation(
                    'pricing_group_package.override_currency_mismatch',
                    "An override must be priced in the group currency ({$groupCurrency}).",
                    ['currency' => strtoupper($currencyCode), 'group_currency' => strtoupper($groupCurrency)],
                );
            }
        }

        return null;
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function pricingGroupId(): int
    {
        return $this->pricingGroupId;
    }

    public function packageId(): int
    {
        return $this->packageId;
    }

    public function status(): PricingRowStatus
    {
        return $this->status;
    }

    public function amountMinor(): ?int
    {
        return $this->amountMinor;
    }

    public function currencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function nameOverride(): ?string
    {
        return $this->nameOverride;
    }

    public function badgeOverride(): ?string
    {
        return $this->badgeOverride;
    }

    public function highlightedOverride(): ?bool
    {
        return $this->highlightedOverride;
    }

    public function displayOrder(): int
    {
        return $this->displayOrder;
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
