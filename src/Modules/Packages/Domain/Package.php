<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Providers\Domain\PaymentMethod;

/**
 * A client's catalogue entry (Phase 11). Holds the catalogue identity plus the
 * four availability dimensions (country / currency / payment method / provider
 * account). An **empty** dimension means "available everywhere for that
 * dimension" (Phase 11 Q2). `id` is null until the repository persists it.
 * Purchase capabilities, pricing, trial config etc. are added by later phases.
 */
final class Package
{
    /**
     * @param array<string, mixed>|null $metadata
     * @param list<string>              $countryCodes  ISO alpha-2, upper-case
     * @param list<string>              $currencyCodes ISO 4217, upper-case
     * @param list<PaymentMethod>       $methods
     * @param list<int>                 $providerAccountIds
     */
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly PackageCode $code,
        private string $name,
        private ?string $description,
        private PackageStatus $status,
        private ?array $metadata,
        private array $countryCodes,
        private array $currencyCodes,
        private array $methods,
        private array $providerAccountIds,
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
            [],
            [],
            [],
            [],
            $now,
            null,
        );
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @param list<string>              $countryCodes
     * @param list<string>              $currencyCodes
     * @param list<PaymentMethod>       $methods
     * @param list<int>                 $providerAccountIds
     */
    public static function fromStorage(
        int $id,
        int $clientId,
        PackageCode $code,
        string $name,
        ?string $description,
        PackageStatus $status,
        ?array $metadata,
        array $countryCodes,
        array $currencyCodes,
        array $methods,
        array $providerAccountIds,
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
            $countryCodes,
            $currencyCodes,
            $methods,
            $providerAccountIds,
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
