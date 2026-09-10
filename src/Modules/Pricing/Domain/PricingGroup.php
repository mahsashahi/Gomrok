<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

use DateTimeImmutable;

/**
 * A client's pricing group (Phase 13): a set of countries + one currency + an
 * optional `device_type` filter + a `priority`. `isDefault` is the fallback (no
 * countries; forced last by the resolver). Overlapping country membership across
 * groups is allowed — the lowest `priority` wins. `id` is null until persisted.
 */
final class PricingGroup
{
    /**
     * @param list<string> $countryCodes ISO alpha-2, upper-case
     */
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly PricingGroupSlug $slug,
        private string $name,
        private int $priority,
        private readonly ?string $deviceType,
        private readonly string $currencyCode,
        private readonly bool $isDefault,
        private PricingGroupStatus $status,
        private array $countryCodes,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function define(
        int $clientId,
        PricingGroupSlug $slug,
        string $name,
        int $priority,
        ?string $deviceType,
        string $currencyCode,
        bool $isDefault,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            $clientId,
            $slug,
            $name,
            max(0, $priority),
            $deviceType,
            strtoupper($currencyCode),
            $isDefault,
            PricingGroupStatus::Active,
            [],
            $now,
            null,
        );
    }

    /**
     * @param list<string> $countryCodes
     */
    public static function fromStorage(
        int $id,
        int $clientId,
        PricingGroupSlug $slug,
        string $name,
        int $priority,
        ?string $deviceType,
        string $currencyCode,
        bool $isDefault,
        PricingGroupStatus $status,
        array $countryCodes,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $clientId,
            $slug,
            $name,
            $priority,
            $deviceType,
            $currencyCode,
            $isDefault,
            $status,
            $countryCodes,
            $createdAt,
            $updatedAt,
        );
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function rename(string $name, DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->updatedAt = $now;
    }

    public function reprioritise(int $priority, DateTimeImmutable $now): void
    {
        $this->priority = max(0, $priority);
        $this->updatedAt = $now;
    }

    /**
     * @param list<string> $countryCodes
     */
    public function setCountries(array $countryCodes, DateTimeImmutable $now): void
    {
        $seen = [];
        foreach ($countryCodes as $code) {
            $normalised = strtoupper(trim($code));
            if ($normalised !== '') {
                $seen[$normalised] = true;
            }
        }
        $this->countryCodes = array_keys($seen);
        $this->updatedAt = $now;
    }

    public function disable(DateTimeImmutable $now): void
    {
        if ($this->status === PricingGroupStatus::Disabled) {
            return;
        }
        $this->status = PricingGroupStatus::Disabled;
        $this->updatedAt = $now;
    }

    public function enable(DateTimeImmutable $now): void
    {
        if ($this->status === PricingGroupStatus::Active) {
            return;
        }
        $this->status = PricingGroupStatus::Active;
        $this->updatedAt = $now;
    }

    public function isActive(): bool
    {
        return $this->status === PricingGroupStatus::Active;
    }

    public function coversCountry(string $countryCode): bool
    {
        return \in_array(strtoupper($countryCode), $this->countryCodes, true);
    }

    public function appliesToDevice(?string $deviceType): bool
    {
        return $this->deviceType === null || $this->deviceType === $deviceType;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function slug(): PricingGroupSlug
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function deviceType(): ?string
    {
        return $this->deviceType;
    }

    public function currencyCode(): string
    {
        return $this->currencyCode;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function status(): PricingGroupStatus
    {
        return $this->status;
    }

    /**
     * @return list<string>
     */
    public function countryCodes(): array
    {
        return $this->countryCodes;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
