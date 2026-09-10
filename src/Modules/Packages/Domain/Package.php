<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;

/**
 * A client's catalogue entry. Holds the catalogue identity, the four
 * availability dimensions (Phase 11 — empty = available everywhere), the
 * supported purchase types with trial/duration config and their per-country
 * overrides (Phase 12), and the display fields `badge` / `highlighted` /
 * `clientPackageId`. `id` is null until the repository persists it. Pricing is
 * Phase 13.
 */
final class Package
{
    /**
     * @param array<string, mixed>|null              $metadata
     * @param list<string>                           $countryCodes  ISO alpha-2, upper-case
     * @param list<string>                           $currencyCodes ISO 4217, upper-case
     * @param list<PaymentMethod>                    $methods
     * @param list<int>                              $providerAccountIds
     * @param list<PackagePurchaseCapability>        $purchaseCapabilities
     * @param list<PackageCountryPurchaseCapability> $countryPurchaseCapabilities
     */
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly PackageCode $code,
        private string $name,
        private ?string $description,
        private PackageStatus $status,
        private ?array $metadata,
        private ?string $badge,
        private bool $highlighted,
        private ?string $clientPackageId,
        private array $countryCodes,
        private array $currencyCodes,
        private array $methods,
        private array $providerAccountIds,
        private array $purchaseCapabilities,
        private array $countryPurchaseCapabilities,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function create(
        int $clientId,
        PackageCode $code,
        string $name,
        ?string $description,
        ?array $metadata,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            $clientId,
            $code,
            $name,
            self::normaliseDescription($description),
            PackageStatus::Active,
            $metadata,
            null,
            false,
            null,
            [],
            [],
            [],
            [],
            [],
            [],
            $now,
            null,
        );
    }

    /**
     * @param array<string, mixed>|null              $metadata
     * @param list<string>                           $countryCodes
     * @param list<string>                           $currencyCodes
     * @param list<PaymentMethod>                    $methods
     * @param list<int>                              $providerAccountIds
     * @param list<PackagePurchaseCapability>        $purchaseCapabilities
     * @param list<PackageCountryPurchaseCapability> $countryPurchaseCapabilities
     */
    public static function fromStorage(
        int $id,
        int $clientId,
        PackageCode $code,
        string $name,
        ?string $description,
        PackageStatus $status,
        ?array $metadata,
        ?string $badge,
        bool $highlighted,
        ?string $clientPackageId,
        array $countryCodes,
        array $currencyCodes,
        array $methods,
        array $providerAccountIds,
        array $purchaseCapabilities,
        array $countryPurchaseCapabilities,
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
            $metadata,
            $badge,
            $highlighted,
            $clientPackageId,
            $countryCodes,
            $currencyCodes,
            $methods,
            $providerAccountIds,
            $purchaseCapabilities,
            $countryPurchaseCapabilities,
            $createdAt,
            $updatedAt,
        );
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function update(?string $name, ?string $description, ?array $metadata, bool $clearDescription, bool $clearMetadata, DateTimeImmutable $now): void
    {
        if ($name !== null && trim($name) !== '') {
            $this->name = trim($name);
        }
        if ($clearDescription) {
            $this->description = null;
        } elseif ($description !== null) {
            $this->description = self::normaliseDescription($description);
        }
        if ($clearMetadata) {
            $this->metadata = null;
        } elseif ($metadata !== null) {
            $this->metadata = $metadata;
        }
        $this->updatedAt = $now;
    }

    public function updateDisplay(
        ?string $badge,
        ?bool $highlighted,
        ?string $clientPackageId,
        bool $clearBadge,
        bool $clearClientPackageId,
        DateTimeImmutable $now,
    ): void {
        if ($clearBadge) {
            $this->badge = null;
        } elseif ($badge !== null && trim($badge) !== '') {
            $this->badge = trim($badge);
        }
        if ($highlighted !== null) {
            $this->highlighted = $highlighted;
        }
        if ($clearClientPackageId) {
            $this->clientPackageId = null;
        } elseif ($clientPackageId !== null && trim($clientPackageId) !== '') {
            $this->clientPackageId = trim($clientPackageId);
        }
        $this->updatedAt = $now;
    }

    /**
     * @param list<PackagePurchaseCapability> $capabilities
     */
    public function setPurchaseCapabilities(array $capabilities, DateTimeImmutable $now): void
    {
        $seen = [];
        foreach ($capabilities as $capability) {
            $seen[$capability->purchaseType->value] = $capability;
        }
        $this->purchaseCapabilities = array_values($seen);
        $this->updatedAt = $now;
    }

    /**
     * @param list<PackageCountryPurchaseCapability> $overrides
     */
    public function setCountryPurchaseCapabilities(array $overrides, DateTimeImmutable $now): void
    {
        $seen = [];
        foreach ($overrides as $override) {
            $seen[$override->countryCode . ':' . $override->purchaseType->value] = $override;
        }
        $this->countryPurchaseCapabilities = array_values($seen);
        $this->updatedAt = $now;
    }

    /**
     * The purchase types this package can be sold as in a given country: the
     * global set, replaced by the country override when any rows exist for that
     * country. The market / provider intersection is the payment flow's job.
     *
     * @return list<PackagePurchaseCapability>
     */
    public function effectiveCapabilities(?string $countryCode): array
    {
        if ($countryCode === null) {
            return $this->purchaseCapabilities;
        }

        $country = strtoupper(trim($countryCode));
        $overriddenTypes = [];
        foreach ($this->countryPurchaseCapabilities as $override) {
            if ($override->countryCode === $country) {
                $overriddenTypes[$override->purchaseType->value] = true;
            }
        }

        if ($overriddenTypes === []) {
            return $this->purchaseCapabilities;
        }

        return array_values(array_filter(
            $this->purchaseCapabilities,
            static fn (PackagePurchaseCapability $c): bool => isset($overriddenTypes[$c->purchaseType->value]),
        ));
    }

    public function supportsPurchaseTypeGlobally(PurchaseType $purchaseType): bool
    {
        foreach ($this->purchaseCapabilities as $capability) {
            if ($capability->purchaseType === $purchaseType) {
                return true;
            }
        }

        return false;
    }

    /** A package with no global purchase capability cannot be sold. */
    public function isSellable(): bool
    {
        return $this->isActive() && $this->purchaseCapabilities !== [];
    }

    /**
     * @param list<string>        $countryCodes
     * @param list<string>        $currencyCodes
     * @param list<PaymentMethod> $methods
     * @param list<int>           $providerAccountIds
     */
    public function setAvailability(
        array $countryCodes,
        array $currencyCodes,
        array $methods,
        array $providerAccountIds,
        DateTimeImmutable $now,
    ): void {
        $this->countryCodes = self::uniqueUpper($countryCodes);
        $this->currencyCodes = self::uniqueUpper($currencyCodes);
        $this->methods = self::uniqueMethods($methods);
        $this->providerAccountIds = self::uniqueInts($providerAccountIds);
        $this->updatedAt = $now;
    }

    public function disable(DateTimeImmutable $now): void
    {
        if ($this->status === PackageStatus::Disabled) {
            return;
        }
        $this->status = PackageStatus::Disabled;
        $this->updatedAt = $now;
    }

    public function enable(DateTimeImmutable $now): void
    {
        if ($this->status === PackageStatus::Active) {
            return;
        }
        $this->status = PackageStatus::Active;
        $this->updatedAt = $now;
    }

    public function isActive(): bool
    {
        return $this->status === PackageStatus::Active;
    }

    public function availableInCountry(string $countryCode): bool
    {
        return $this->countryCodes === [] || \in_array(strtoupper($countryCode), $this->countryCodes, true);
    }

    public function availableInCurrency(string $currencyCode): bool
    {
        return $this->currencyCodes === [] || \in_array(strtoupper($currencyCode), $this->currencyCodes, true);
    }

    public function availableViaMethod(PaymentMethod $method): bool
    {
        if ($this->methods === []) {
            return true;
        }
        foreach ($this->methods as $allowed) {
            if ($allowed === $method) {
                return true;
            }
        }

        return false;
    }

    public function availableViaProviderAccount(int $providerAccountId): bool
    {
        return $this->providerAccountIds === [] || \in_array($providerAccountId, $this->providerAccountIds, true);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function code(): PackageCode
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

    public function status(): PackageStatus
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function metadata(): ?array
    {
        return $this->metadata;
    }

    public function badge(): ?string
    {
        return $this->badge;
    }

    public function highlighted(): bool
    {
        return $this->highlighted;
    }

    public function clientPackageId(): ?string
    {
        return $this->clientPackageId;
    }

    /**
     * @return list<PackagePurchaseCapability>
     */
    public function purchaseCapabilities(): array
    {
        return $this->purchaseCapabilities;
    }

    /**
     * @return list<PackageCountryPurchaseCapability>
     */
    public function countryPurchaseCapabilities(): array
    {
        return $this->countryPurchaseCapabilities;
    }

    /**
     * @return list<string>
     */
    public function countryCodes(): array
    {
        return $this->countryCodes;
    }

    /**
     * @return list<string>
     */
    public function currencyCodes(): array
    {
        return $this->currencyCodes;
    }

    /**
     * @return list<PaymentMethod>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    /**
     * @return list<int>
     */
    public function providerAccountIds(): array
    {
        return $this->providerAccountIds;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function normaliseDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $trimmed = trim($description);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function uniqueUpper(array $values): array
    {
        $seen = [];
        foreach ($values as $value) {
            $normalised = strtoupper(trim($value));
            if ($normalised !== '') {
                $seen[$normalised] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * @param list<PaymentMethod> $methods
     *
     * @return list<PaymentMethod>
     */
    private static function uniqueMethods(array $methods): array
    {
        $seen = [];
        foreach ($methods as $method) {
            $seen[$method->value] = $method;
        }

        return array_values($seen);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function uniqueInts(array $ids): array
    {
        $seen = [];
        foreach ($ids as $id) {
            $seen[$id] = true;
        }

        return array_keys($seen);
    }
}
