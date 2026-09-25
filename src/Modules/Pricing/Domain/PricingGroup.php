<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

use DateTimeImmutable;

/**
 * A client's pricing group (Phase 13): a set of countries + one currency + an
 * optional `device_type` filter + a `priority`. `isDefault` is the fallback (no
 * countries; forced last by the resolver). Overlapping country membership across
 * groups is allowed — the lowest `priority` wins. `id` is null until persisted.
 *
 * `currencyCode` and `isDefault` are `readonly` — intentionally immutable post-
 * creation, not merely unimplemented (2026-09-24 pricing-group edit-parity
 * review):
 *
 * - `currencyCode`: `pricing_group_packages`/`price_list_packages` override
 *   rows each persist their own `currency_code` at the time they were set
 *   (see the migrations) — changing the group's currency afterward would
 *   silently strand every already-set override in a currency the group no
 *   longer claims, a real correctness bug, not a workflow inconvenience.
 *   Reprice by creating a new group in the desired currency instead.
 * - `isDefault`: enforced client-wide as a singleton at creation
 *   ({@see \Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupHandler}),
 *   and carries structurally different rules for as long as it holds true
 *   (no countries, {@see disable()} refuses it). Toggling it in place would
 *   need to atomically demote whichever group currently holds it — a
 *   distinct operation this aggregate's simple field setters aren't safe to
 *   also perform. Every other field (`name`, `slug`, `priority`,
 *   `deviceType`, `countryCodes`, `status`) has a setter and an Admin-facing
 *   edit path.
 */
final class PricingGroup
{
    /**
     * @param list<string> $countryCodes ISO alpha-2, upper-case
     */
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private PricingGroupSlug $slug,
        private string $name,
        private int $priority,
        private ?string $deviceType,
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
     * A slug rename. Safe to change post-creation — nothing else persists it
     * as a foreign reference (every cross-table reference is the integer
     * `id`); the only cost is that a previously shared `?group=old-slug`
     * admin URL stops resolving, same trade-off as renaming any slug.
     */
    public function changeSlug(PricingGroupSlug $slug, DateTimeImmutable $now): void
    {
        $this->slug = $slug;
        $this->updatedAt = $now;
    }

    public function setDeviceType(?string $deviceType, DateTimeImmutable $now): void
    {
        $this->deviceType = $deviceType;
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
