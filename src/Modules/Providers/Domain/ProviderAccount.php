<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

use DateTimeImmutable;

/**
 * A client's connection to one provider type in one {@see ProviderAccountMode}.
 * Holds the (encrypted) credentials, the markets/methods it serves, and its
 * inbound endpoints. `id` is null until the repository persists it; the
 * effective capabilities come from the provider type's declaration (Phase 8),
 * not from this aggregate.
 */
final class ProviderAccount
{
    /**
     * @param list<string>                  $countryCodes
     * @param list<PaymentMethod>           $methods
     * @param list<ProviderAccountEndpoint> $endpoints
     */
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly int $providerTypeId,
        private readonly ProviderAccountSlug $slug,
        private string $name,
        private readonly ProviderAccountMode $mode,
        private ProviderAccountStatus $status,
        private ?string $publicKey,
        private EncryptedSecret $secret,
        private array $countryCodes,
        private array $methods,
        private array $endpoints,
        private ?DateTimeImmutable $disabledAt,
        private ?int $disabledBy,
        private ?string $disabledReason,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param list<string>        $countryCodes
     * @param list<PaymentMethod> $methods
     */
    public static function register(
        int $clientId,
        int $providerTypeId,
        ProviderAccountSlug $slug,
        string $name,
        ProviderAccountMode $mode,
        ?string $publicKey,
        EncryptedSecret $secret,
        array $countryCodes,
        array $methods,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            $clientId,
            $providerTypeId,
            $slug,
            $name,
            $mode,
            ProviderAccountStatus::Active,
            $publicKey,
            $secret,
            self::uniqueStrings($countryCodes),
            self::uniqueMethods($methods),
            [],
            null,
            null,
            null,
            $now,
            null,
        );
    }

    /**
     * @param list<string>                  $countryCodes
     * @param list<PaymentMethod>           $methods
     * @param list<ProviderAccountEndpoint> $endpoints
     */
    public static function fromStorage(
        int $id,
        int $clientId,
        int $providerTypeId,
        ProviderAccountSlug $slug,
        string $name,
        ProviderAccountMode $mode,
        ProviderAccountStatus $status,
        ?string $publicKey,
        EncryptedSecret $secret,
        array $countryCodes,
        array $methods,
        array $endpoints,
        ?DateTimeImmutable $disabledAt,
        ?int $disabledBy,
        ?string $disabledReason,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $clientId,
            $providerTypeId,
            $slug,
            $name,
            $mode,
            $status,
            $publicKey,
            $secret,
            $countryCodes,
            $methods,
            $endpoints,
            $disabledAt,
            $disabledBy,
            $disabledReason,
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

    /**
     * @param list<string>        $countryCodes
     * @param list<PaymentMethod> $methods
     */
    public function changeMarkets(array $countryCodes, array $methods, DateTimeImmutable $now): void
    {
        $this->countryCodes = self::uniqueStrings($countryCodes);
        $this->methods = self::uniqueMethods($methods);
        $this->updatedAt = $now;
    }

    public function rotateSecret(EncryptedSecret $secret, DateTimeImmutable $now): void
    {
        $this->secret = $secret;
        $this->updatedAt = $now;
    }

    public function replacePublicKey(?string $publicKey, DateTimeImmutable $now): void
    {
        $this->publicKey = $publicKey;
        $this->updatedAt = $now;
    }

    public function disable(?int $disabledBy, ?string $reason, DateTimeImmutable $now): void
    {
        if ($this->status === ProviderAccountStatus::Disabled) {
            return;
        }

        $this->status = ProviderAccountStatus::Disabled;
        $this->disabledAt = $now;
        $this->disabledBy = $disabledBy;
        $this->disabledReason = $reason;
        $this->updatedAt = $now;
    }

    public function enable(DateTimeImmutable $now): void
    {
        if ($this->status === ProviderAccountStatus::Active) {
            return;
        }

        $this->status = ProviderAccountStatus::Active;
        $this->disabledAt = null;
        $this->disabledBy = null;
        $this->disabledReason = null;
        $this->updatedAt = $now;
    }

    /**
     * Add an endpoint, deactivating any existing active endpoint of the same
     * kind (one active per kind).
     */
    public function addEndpoint(ProviderAccountEndpoint $endpoint, DateTimeImmutable $now): void
    {
        if ($endpoint->isActive()) {
            foreach ($this->endpoints as $existing) {
                if ($existing->kind() === $endpoint->kind() && $existing->isActive()) {
                    $existing->deactivate();
                }
            }
        }

        $this->endpoints[] = $endpoint;
        $this->updatedAt = $now;
    }

    public function deactivateEndpoints(EndpointKind $kind, DateTimeImmutable $now): bool
    {
        $changed = false;
        foreach ($this->endpoints as $endpoint) {
            if ($endpoint->kind() === $kind && $endpoint->isActive()) {
                $endpoint->deactivate();
                $changed = true;
            }
        }

        if ($changed) {
            $this->updatedAt = $now;
        }

        return $changed;
    }

    public function activeEndpoint(EndpointKind $kind): ?ProviderAccountEndpoint
    {
        foreach ($this->endpoints as $endpoint) {
            if ($endpoint->kind() === $kind && $endpoint->isActive()) {
                return $endpoint;
            }
        }

        return null;
    }

    public function isActive(): bool
    {
        return $this->status === ProviderAccountStatus::Active;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function providerTypeId(): int
    {
        return $this->providerTypeId;
    }

    public function slug(): ProviderAccountSlug
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function mode(): ProviderAccountMode
    {
        return $this->mode;
    }

    public function status(): ProviderAccountStatus
    {
        return $this->status;
    }

    public function publicKey(): ?string
    {
        return $this->publicKey;
    }

    public function secret(): EncryptedSecret
    {
        return $this->secret;
    }

    /**
     * @return list<string>
     */
    public function countryCodes(): array
    {
        return $this->countryCodes;
    }

    /**
     * @return list<PaymentMethod>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    /**
     * @return list<ProviderAccountEndpoint>
     */
    public function endpoints(): array
    {
        return $this->endpoints;
    }

    public function disabledAt(): ?DateTimeImmutable
    {
        return $this->disabledAt;
    }

    public function disabledBy(): ?int
    {
        return $this->disabledBy;
    }

    public function disabledReason(): ?string
    {
        return $this->disabledReason;
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
    private static function uniqueStrings(array $values): array
    {
        return array_values(array_unique(array_map(strtoupper(...), $values)));
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
}
