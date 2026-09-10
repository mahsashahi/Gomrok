<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

use DateTimeImmutable;
use Gomrok\Shared\Domain\DomainError;

/**
 * An A/B price list inside a pricing group (Phase 15). Exactly one list per
 * group is the **control** (`isControl`, `factor` pinned at `1.0000`, always
 * enabled, never deleted). A non-control list shifts the resolved base price by
 * `factor` — or, per package, by an exact {@see PriceListPackage} amount.
 *
 * `id` is null until persisted. `factor` is a decimal string ("0.9000").
 */
final class PriceList
{
    public const CONTROL_NAME = 'List A · control';
    public const CONTROL_FACTOR = '1.0000';

    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly int $pricingGroupId,
        private string $name,
        private readonly bool $isControl,
        private string $factor,
        private bool $isEnabled,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function control(int $clientId, int $pricingGroupId, DateTimeImmutable $now): self
    {
        return new self(null, $clientId, $pricingGroupId, self::CONTROL_NAME, true, self::CONTROL_FACTOR, true, $now, null);
    }

    public static function experiment(int $clientId, int $pricingGroupId, string $name, string $factor, DateTimeImmutable $now): self
    {
        return new self(null, $clientId, $pricingGroupId, trim($name), false, self::normaliseFactor($factor), true, $now, null);
    }

    public static function fromStorage(
        int $id,
        int $clientId,
        int $pricingGroupId,
        string $name,
        bool $isControl,
        string $factor,
        bool $isEnabled,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $clientId, $pricingGroupId, $name, $isControl, $factor, $isEnabled, $createdAt, $updatedAt);
    }

    /**
     * Structural validation for a create / update. A control list is immutable
     * in factor and enablement.
     */
    public static function validateFactor(string $factor): ?DomainError
    {
        if (preg_match('/^\d{1,2}(\.\d{1,4})?$/', trim($factor)) !== 1) {
            return DomainError::validation('price_list.invalid_factor', "Factor '{$factor}' must be a decimal with up to 2 integer and 4 fractional digits.");
        }
        if ((float) $factor <= 0.0) {
            return DomainError::validation('price_list.non_positive_factor', 'Factor must be greater than zero.');
        }

        return null;
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function rename(string $name, DateTimeImmutable $now): void
    {
        $this->name = trim($name);
        $this->updatedAt = $now;
    }

    public function changeFactor(string $factor, DateTimeImmutable $now): ?DomainError
    {
        if ($this->isControl) {
            return DomainError::validation('price_list.control_factor_locked', 'The control list factor is fixed at 1.0000.');
        }
        $error = self::validateFactor($factor);
        if ($error !== null) {
            return $error;
        }
        $this->factor = self::normaliseFactor($factor);
        $this->updatedAt = $now;

        return null;
    }

    public function enable(DateTimeImmutable $now): void
    {
        if (!$this->isEnabled) {
            $this->isEnabled = true;
            $this->updatedAt = $now;
        }
    }

    public function disable(DateTimeImmutable $now): ?DomainError
    {
        if ($this->isControl) {
            return DomainError::validation('price_list.cannot_disable_control', 'The control list cannot be disabled.');
        }
        if ($this->isEnabled) {
            $this->isEnabled = false;
            $this->updatedAt = $now;
        }

        return null;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function pricingGroupId(): int
    {
        return $this->pricingGroupId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isControl(): bool
    {
        return $this->isControl;
    }

    public function factor(): string
    {
        return $this->factor;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    /** A no-op list leaves the base price untouched (control, or `factor = 1.0000`). */
    public function isNeutral(): bool
    {
        return $this->isControl || (float) $this->factor === 1.0;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function normaliseFactor(string $factor): string
    {
        return number_format((float) trim($factor), 4, '.', '');
    }
}
