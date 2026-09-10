<?php

declare(strict_types=1);

namespace Gomrok\Shared\Domain;

use Brick\Money\Currency as BrickCurrency;
use Brick\Money\Exception\UnknownCurrencyException;
use InvalidArgumentException;
use Stringable;

/**
 * An ISO 4217 currency. Thin wrapper over brick/money's currency data so the
 * domain never depends on the library directly.
 */
final readonly class Currency implements Stringable
{
    private function __construct(private BrickCurrency $currency)
    {
    }

    public static function of(string $code): self
    {
        try {
            return new self(BrickCurrency::of(strtoupper($code)));
        } catch (UnknownCurrencyException $e) {
            throw new InvalidArgumentException("Unknown ISO 4217 currency code: {$code}", 0, $e);
        }
    }

    public function code(): string
    {
        return $this->currency->getCurrencyCode();
    }

    /**
     * Number of decimal places this currency uses (USD/EUR = 2, JPY = 0, BHD/KWD = 3).
     */
    public function minorUnitScale(): int
    {
        return $this->currency->getDefaultFractionDigits();
    }

    public function equals(self $other): bool
    {
        return $this->code() === $other->code();
    }

    public function __toString(): string
    {
        return $this->code();
    }

    /**
     * @internal only for {@see Money}
     */
    public function toBrickCurrency(): BrickCurrency
    {
        return $this->currency;
    }
}
