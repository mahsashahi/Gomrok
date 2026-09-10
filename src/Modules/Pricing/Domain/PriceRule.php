<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Domain\DomainError;

/**
 * One layered price / availability override for a `(client, package)` (Phase
 * 14). Each of the seven dimension fields is a wildcard when null. `id` is null
 * until persisted.
 *
 * The dimension priority order (most specific first, used to break a
 * matched-count tie in {@see \Gomrok\Modules\Pricing\Application\PriceRuleResolver}):
 * `subscription_interval` > `purchase_type` > `payment_method` >
 * `provider_account_id` > `currency_code` > `country_code` > `pricing_group_id`.
 */
final class PriceRule
{
    /** Dimension names, most specific first. */
    public const DIMENSIONS = [
        'subscription_interval',
        'purchase_type',
        'payment_method',
        'provider_account_id',
        'currency_code',
        'country_code',
        'pricing_group_id',
    ];

    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly int $packageId,
        private readonly ?int $pricingGroupId,
        private readonly ?string $countryCode,
        private readonly ?int $providerAccountId,
        private readonly ?PaymentMethod $paymentMethod,
        private readonly ?PurchaseType $purchaseType,
        private readonly ?SubscriptionInterval $subscriptionInterval,
        private readonly ?string $currencyCode,
        private bool $isAvailable,
        private ?int $amountMinor,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        int $clientId,
        int $packageId,
        ?int $pricingGroupId,
        ?string $countryCode,
        ?int $providerAccountId,
        ?PaymentMethod $paymentMethod,
        ?PurchaseType $purchaseType,
        ?SubscriptionInterval $subscriptionInterval,
        ?string $currencyCode,
        bool $isAvailable,
        ?int $amountMinor,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            $clientId,
            $packageId,
            $pricingGroupId,
            $countryCode !== null ? strtoupper($countryCode) : null,
            $providerAccountId,
            $paymentMethod,
            $purchaseType,
            $subscriptionInterval,
            $currencyCode !== null ? strtoupper($currencyCode) : null,
            $isAvailable,
            $isAvailable ? $amountMinor : null,
            $now,
            null,
        );
    }

    public static function fromStorage(
        int $id,
        int $clientId,
        int $packageId,
        ?int $pricingGroupId,
        ?string $countryCode,
        ?int $providerAccountId,
        ?PaymentMethod $paymentMethod,
        ?PurchaseType $purchaseType,
        ?SubscriptionInterval $subscriptionInterval,
        ?string $currencyCode,
        bool $isAvailable,
        ?int $amountMinor,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $clientId,
            $packageId,
            $pricingGroupId,
            $countryCode,
            $providerAccountId,
            $paymentMethod,
            $purchaseType,
            $subscriptionInterval,
            $currencyCode,
            $isAvailable,
            $amountMinor,
            $createdAt,
            $updatedAt,
        );
    }

    public static function validate(
        ?int $pricingGroupId,
        ?PurchaseType $purchaseType,
        ?SubscriptionInterval $subscriptionInterval,
        bool $isAvailable,
        ?int $amountMinor,
        ?string $currencyCode,
    ): ?DomainError {
        if ($subscriptionInterval !== null && !\in_array($purchaseType, [PurchaseType::Subscription, PurchaseType::RecurringPayment], true)) {
            return DomainError::validation(
                'price_rule.interval_needs_subscription',
                'A subscription_interval dimension is only valid with purchase_type subscription or recurring_payment.',
            );
        }

        if ($isAvailable) {
            if ($amountMinor === null || $amountMinor < 0) {
                return DomainError::validation('price_rule.amount_required', 'An available price rule needs a non-negative amount_minor.');
            }
            if ($pricingGroupId === null && $currencyCode === null) {
                return DomainError::validation(
                    'price_rule.needs_group_or_currency',
                    'An available price rule must pin a pricing_group_id or a currency_code so its amount currency is unambiguous.',
                );
            }
        } elseif ($amountMinor !== null || $currencyCode !== null) {
            return DomainError::validation('price_rule.unavailable_has_amount', 'An unavailable price rule cannot carry an amount or currency.');
        }

        return null;
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function change(bool $isAvailable, ?int $amountMinor, DateTimeImmutable $now): void
    {
        $this->isAvailable = $isAvailable;
        $this->amountMinor = $isAvailable ? $amountMinor : null;
        $this->updatedAt = $now;
    }

    /**
     * True when every non-null dimension of this rule equals the request value.
     */
    public function matches(
        int $pricingGroupId,
        string $country,
        ?int $providerAccountId,
        ?PaymentMethod $paymentMethod,
        ?PurchaseType $purchaseType,
        ?SubscriptionInterval $subscriptionInterval,
        string $currency,
    ): bool {
        if ($this->pricingGroupId !== null && $this->pricingGroupId !== $pricingGroupId) {
            return false;
        }
        if ($this->countryCode !== null && $this->countryCode !== strtoupper($country)) {
            return false;
        }
        if ($this->providerAccountId !== null && $this->providerAccountId !== $providerAccountId) {
            return false;
        }
        if ($this->paymentMethod !== null && $this->paymentMethod !== $paymentMethod) {
            return false;
        }
        if ($this->purchaseType !== null && $this->purchaseType !== $purchaseType) {
            return false;
        }
        if ($this->subscriptionInterval !== null && $this->subscriptionInterval !== $subscriptionInterval) {
            return false;
        }
        if ($this->currencyCode !== null && $this->currencyCode !== strtoupper($currency)) {
            return false;
        }

        return true;
    }

    /**
     * The dimension names this rule pins, most specific first.
     *
     * @return list<string>
     */
    public function pinnedDimensions(): array
    {
        $pinned = [
            'subscription_interval' => $this->subscriptionInterval !== null,
            'purchase_type' => $this->purchaseType !== null,
            'payment_method' => $this->paymentMethod !== null,
            'provider_account_id' => $this->providerAccountId !== null,
            'currency_code' => $this->currencyCode !== null,
            'country_code' => $this->countryCode !== null,
            'pricing_group_id' => $this->pricingGroupId !== null,
        ];

        return array_keys(array_filter($pinned));
    }

    public function specificity(): int
    {
        return \count($this->pinnedDimensions());
    }

    /**
     * Tie-break vector: 1/0 per dimension in {@see DIMENSIONS} order.
     *
     * @return list<int>
     */
    public function tieBreak(): array
    {
        $pinned = array_flip($this->pinnedDimensions());

        return array_map(static fn (string $d): int => isset($pinned[$d]) ? 1 : 0, self::DIMENSIONS);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function packageId(): int
    {
        return $this->packageId;
    }

    public function pricingGroupId(): ?int
    {
        return $this->pricingGroupId;
    }

    public function countryCode(): ?string
    {
        return $this->countryCode;
    }

    public function providerAccountId(): ?int
    {
        return $this->providerAccountId;
    }

    public function paymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function purchaseType(): ?PurchaseType
    {
        return $this->purchaseType;
    }

    public function subscriptionInterval(): ?SubscriptionInterval
    {
        return $this->subscriptionInterval;
    }

    public function currencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function isAvailable(): bool
    {
        return $this->isAvailable;
    }

    public function amountMinor(): ?int
    {
        return $this->amountMinor;
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
