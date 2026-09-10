<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

use DateTimeImmutable;

/**
 * A client's country → provider routing rule (Phase 10). Binds a set of
 * countries to an ordered list of the client's provider accounts, plus the
 * purchase types / methods that market sells. `isDefault` marks the client's
 * fallback group (no countries — it covers everything no other group claims).
 * `id` is null until the repository persists it.
 */
final class ProviderGroup
{
    /**
     * @param list<string>               $countryCodes  ISO alpha-2, upper-case
     * @param list<ProviderGroupAccount> $accounts      ordered by priority
     * @param list<PurchaseType>         $purchaseTypes
     * @param list<PaymentMethod>        $methods
     */
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private ProviderGroupSlug $slug,
        private string $name,
        private readonly bool $isDefault,
        private readonly ?DeviceType $deviceType,
        private ?string $currencyCode,
        private ProviderGroupStatus $status,
        private array $countryCodes,
        private array $accounts,
        private array $purchaseTypes,
        private array $methods,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function define(
        int $clientId,
        ProviderGroupSlug $slug,
        string $name,
        bool $isDefault,
        ?DeviceType $deviceType,
        ?string $currencyCode,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            $clientId,
            $slug,
            $name,
            $isDefault,
            $deviceType,
            self::normaliseCurrency($currencyCode),
            ProviderGroupStatus::Active,
            [],
            [],
            [],
            [],
            $now,
            null,
        );
    }

    /**
     * @param list<string>               $countryCodes
     * @param list<ProviderGroupAccount> $accounts
     * @param list<PurchaseType>         $purchaseTypes
     * @param list<PaymentMethod>        $methods
     */
    public static function fromStorage(
        int $id,
        int $clientId,
        ProviderGroupSlug $slug,
        string $name,
        bool $isDefault,
        ?DeviceType $deviceType,
        ?string $currencyCode,
        ProviderGroupStatus $status,
        array $countryCodes,
        array $accounts,
        array $purchaseTypes,
        array $methods,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $clientId,
            $slug,
            $name,
            $isDefault,
            $deviceType,
            $currencyCode,
            $status,
            $countryCodes,
            self::sortByPriority($accounts),
            $purchaseTypes,
            $methods,
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

    public function changeCurrency(?string $currencyCode, DateTimeImmutable $now): void
    {
        $this->currencyCode = self::normaliseCurrency($currencyCode);
        $this->updatedAt = $now;
    }

    /**
     * @param list<string> $countryCodes
     */
    public function setCountries(array $countryCodes, DateTimeImmutable $now): void
    {
        $this->countryCodes = self::uniqueUpper($countryCodes);
        $this->updatedAt = $now;
    }

    /**
     * @param list<PurchaseType> $purchaseTypes
     */
    public function setPurchaseTypes(array $purchaseTypes, DateTimeImmutable $now): void
    {
        $seen = [];
        foreach ($purchaseTypes as $purchaseType) {
            $seen[$purchaseType->value] = $purchaseType;
        }
        $this->purchaseTypes = array_values($seen);
        $this->updatedAt = $now;
    }

    /**
     * @param list<PaymentMethod> $methods
     */
    public function setMethods(array $methods, DateTimeImmutable $now): void
    {
        $seen = [];
        foreach ($methods as $method) {
            $seen[$method->value] = $method;
        }
        $this->methods = array_values($seen);
        $this->updatedAt = $now;
    }

    /**
     * Replace the ordered account list. Entries are re-sorted by priority.
     *
     * @param list<ProviderGroupAccount> $accounts
     */
    public function setAccounts(array $accounts, DateTimeImmutable $now): void
    {
        $this->accounts = self::sortByPriority($accounts);
        $this->updatedAt = $now;
    }

    public function disable(DateTimeImmutable $now): void
    {
        if ($this->status === ProviderGroupStatus::Disabled) {
            return;
        }
        $this->status = ProviderGroupStatus::Disabled;
        $this->updatedAt = $now;
    }

    public function enable(DateTimeImmutable $now): void
    {
        if ($this->status === ProviderGroupStatus::Active) {
            return;
        }
        $this->status = ProviderGroupStatus::Active;
        $this->updatedAt = $now;
    }

    public function isActive(): bool
    {
        return $this->status === ProviderGroupStatus::Active;
    }

    public function servesCountry(string $countryCode): bool
    {
        return \in_array(strtoupper($countryCode), $this->countryCodes, true);
    }

    /**
     * True when the group has no device-type restriction or it matches the
     * request's device type.
     */
    public function appliesToDevice(?DeviceType $deviceType): bool
    {
        return $this->deviceType === null || $this->deviceType === $deviceType;
    }

    public function allowsPurchaseType(PurchaseType $purchaseType): bool
    {
        foreach ($this->purchaseTypes as $allowed) {
            if ($allowed === $purchaseType) {
                return true;
            }
        }

        return false;
    }

    public function allowsMethod(PaymentMethod $method): bool
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

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function slug(): ProviderGroupSlug
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function deviceType(): ?DeviceType
    {
        return $this->deviceType;
    }

    public function currencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function status(): ProviderGroupStatus
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

    /**
     * @return list<ProviderGroupAccount> ordered by priority
     */
    public function accounts(): array
    {
        return $this->accounts;
    }

    /**
     * @return list<PurchaseType>
     */
    public function purchaseTypes(): array
    {
        return $this->purchaseTypes;
    }

    /**
     * @return list<PaymentMethod>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function uniqueUpper(array $values): array
    {
        return array_values(array_unique(array_map(
            static fn (string $v): string => strtoupper(trim($v)),
            $values,
        )));
    }

    private static function normaliseCurrency(?string $currencyCode): ?string
    {
        if ($currencyCode === null) {
            return null;
        }
        $trimmed = strtoupper(trim($currencyCode));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param list<ProviderGroupAccount> $accounts
     *
     * @return list<ProviderGroupAccount>
     */
    private static function sortByPriority(array $accounts): array
    {
        usort(
            $accounts,
            static fn (ProviderGroupAccount $a, ProviderGroupAccount $b): int => [$a->priority(), $a->providerAccountId()] <=> [$b->priority(), $b->providerAccountId()],
        );

        return $accounts;
    }
}
