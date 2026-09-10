<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

use DateTimeImmutable;
use Gomrok\Shared\Domain\CountryCode;
use Gomrok\Shared\Domain\Currency;

/**
 * The tenant aggregate: identity (`slug`), display `name`, lifecycle `status`,
 * market defaults, the callback-signing secret, and the set of callback
 * endpoints. API keys are a separate aggregate ({@see ClientApiKey}) — different
 * lifecycle and cardinality.
 *
 * `id` is null until the repository persists it. State-changing methods take the
 * current time and stamp `updatedAt`.
 */
final class Client
{
    /**
     * @param array<string, ClientEndpoint> $endpoints keyed by purpose value
     */
    private function __construct(
        private ?int $id,
        private readonly ClientSlug $slug,
        private string $name,
        private ClientStatus $status,
        private Currency $defaultCurrency,
        private ?CountryCode $defaultCountry,
        private string $timezone,
        private string $notificationSigningSecret,
        private ?DateTimeImmutable $disabledAt,
        private ?int $disabledBy,
        private ?string $disabledReason,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
        private array $endpoints,
    ) {
    }

    public static function register(
        ClientSlug $slug,
        string $name,
        Currency $defaultCurrency,
        ?CountryCode $defaultCountry,
        string $timezone,
        string $notificationSigningSecret,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            $slug,
            $name,
            ClientStatus::Active,
            $defaultCurrency,
            $defaultCountry,
            $timezone,
            $notificationSigningSecret,
            null,
            null,
            null,
            $now,
            null,
            [],
        );
    }

    /**
     * @param list<ClientEndpoint> $endpoints
     */
    public static function fromStorage(
        int $id,
        ClientSlug $slug,
        string $name,
        ClientStatus $status,
        Currency $defaultCurrency,
        ?CountryCode $defaultCountry,
        string $timezone,
        string $notificationSigningSecret,
        ?DateTimeImmutable $disabledAt,
        ?int $disabledBy,
        ?string $disabledReason,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
        array $endpoints,
    ): self {
        $byPurpose = [];
        foreach ($endpoints as $endpoint) {
            $byPurpose[$endpoint->purpose()->value] = $endpoint;
        }

        return new self(
            $id,
            $slug,
            $name,
            $status,
            $defaultCurrency,
            $defaultCountry,
            $timezone,
            $notificationSigningSecret,
            $disabledAt,
            $disabledBy,
            $disabledReason,
            $createdAt,
            $updatedAt,
            $byPurpose,
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

    public function changeDefaults(Currency $currency, ?CountryCode $country, string $timezone, DateTimeImmutable $now): void
    {
        $this->defaultCurrency = $currency;
        $this->defaultCountry = $country;
        $this->timezone = $timezone;
        $this->updatedAt = $now;
    }

    public function rotateSigningSecret(string $secret, DateTimeImmutable $now): void
    {
        $this->notificationSigningSecret = $secret;
        $this->updatedAt = $now;
    }

    public function disable(?int $disabledBy, ?string $reason, DateTimeImmutable $now): void
    {
        if ($this->status === ClientStatus::Disabled) {
            return;
        }

        $this->status = ClientStatus::Disabled;
        $this->disabledAt = $now;
        $this->disabledBy = $disabledBy;
        $this->disabledReason = $reason;
        $this->updatedAt = $now;
    }

    public function enable(DateTimeImmutable $now): void
    {
        if ($this->status === ClientStatus::Active) {
            return;
        }

        $this->status = ClientStatus::Active;
        $this->disabledAt = null;
        $this->disabledBy = null;
        $this->disabledReason = null;
        $this->updatedAt = $now;
    }

    public function setEndpoint(EndpointPurpose $purpose, string $url, DateTimeImmutable $now): void
    {
        $existing = $this->endpoints[$purpose->value] ?? null;

        if ($existing === null) {
            $this->endpoints[$purpose->value] = ClientEndpoint::register($purpose, $url);
        } else {
            $existing->changeUrl($url);
            $existing->activate();
        }

        $this->updatedAt = $now;
    }

    public function removeEndpoint(EndpointPurpose $purpose, DateTimeImmutable $now): void
    {
        if (!isset($this->endpoints[$purpose->value])) {
            return;
        }

        unset($this->endpoints[$purpose->value]);
        $this->updatedAt = $now;
    }

    public function isActive(): bool
    {
        return $this->status === ClientStatus::Active;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function slug(): ClientSlug
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): ClientStatus
    {
        return $this->status;
    }

    public function defaultCurrency(): Currency
    {
        return $this->defaultCurrency;
    }

    public function defaultCountry(): ?CountryCode
    {
        return $this->defaultCountry;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function notificationSigningSecret(): string
    {
        return $this->notificationSigningSecret;
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
     * @return list<ClientEndpoint>
     */
    public function endpoints(): array
    {
        return array_values($this->endpoints);
    }
}
