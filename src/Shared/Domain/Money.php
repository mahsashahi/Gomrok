<?php

declare(strict_types=1);

namespace Gomrok\Shared\Domain;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use Stringable;

/**
 * An immutable monetary amount in a single {@see Currency}, backed by brick/money.
 *
 * Stored as integer minor units + an ISO 4217 code. All rounding is `HALF_EVEN`
 * (banker's rounding). Arithmetic across different currencies throws
 * `Brick\Money\Exception\MoneyMismatchException` — that is a programmer error and
 * is left to surface as a 500 (see the error model in Architecture.md §11).
 */
final readonly class Money implements Stringable
{
    private const ROUNDING = RoundingMode::HALF_EVEN;

    private function __construct(private BrickMoney $money)
    {
    }

    public static function fromMinor(int $minorAmount, Currency $currency): self
    {
        return new self(BrickMoney::ofMinor($minorAmount, $currency->toBrickCurrency()));
    }

    public static function zero(Currency $currency): self
    {
        return new self(BrickMoney::zero($currency->toBrickCurrency()));
    }

    public function currency(): Currency
    {
        return Currency::of($this->money->getCurrency()->getCurrencyCode());
    }

    /**
     * The amount in minor units (cents, etc.).
     */
    public function toMinor(): int
    {
        return $this->money->getMinorAmount()->toInt();
    }

    /**
     * The amount as a plain decimal string in major units, e.g. `"12.34"` — no
     * currency code, no locale formatting. Use {@see format()} for display.
     */
    public function amount(): string
    {
        return (string) $this->money->getAmount();
    }

    public function plus(self $addend): self
    {
        return new self($this->money->plus($addend->money));
    }

    public function minus(self $subtrahend): self
    {
        return new self($this->money->minus($subtrahend->money));
    }

    public function multipliedBy(int|string $factor): self
    {
        return new self($this->money->multipliedBy($factor, self::ROUNDING));
    }

    /**
     * `percentage('12.5')` → 12.5% of this amount.
     */
    public function percentage(int|string $percent): self
    {
        $fraction = BigRational::of((string) $percent)->dividedBy(100);

        return new self($this->money->multipliedBy($fraction, self::ROUNDING));
    }

    /**
     * This amount as a decimal ratio of another (same currency), e.g.
     * `EUR 25 -> ratioOf(EUR 100)` = `"0.25"`. Returned as a string to keep the
     * result out of brick's types.
     */
    public function ratioOf(self $whole): string
    {
        return (string) $this->money->getAmount()->dividedBy(
            $whole->money->getAmount(),
            12,
            self::ROUNDING,
        );
    }

    /**
     * Split this amount by integer ratios; the remainder lands on the first parts
     * so the pieces always sum back to this amount.
     *
     * @return list<self>
     */
    public function allocate(int ...$ratios): array
    {
        return array_map(
            static fn (BrickMoney $part): self => new self($part),
            array_values($this->money->allocate(...$ratios)),
        );
    }

    /**
     * Convert to another currency at a caller-supplied rate. `Money` never fetches
     * rates — the Pricing module / caller provides one.
     */
    public function convertTo(Currency $target, string $rate): self
    {
        return new self(
            $this->money->convertedTo($target->toBrickCurrency(), $rate, roundingMode: self::ROUNDING),
        );
    }

    /**
     * Locale-aware formatting via `ext-intl`, e.g. `"€1,234.50"`.
     */
    public function format(string $locale): string
    {
        return $this->money->formatTo($locale);
    }

    public function equals(self $other): bool
    {
        return $this->currency()->equals($other->currency())
            && $this->money->getMinorAmount()->isEqualTo($other->money->getMinorAmount());
    }

    public function isZero(): bool
    {
        return $this->money->isZero();
    }

    public function isPositive(): bool
    {
        return $this->money->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->money->isNegative();
    }

    /**
     * Machine-readable `"USD 12.34"`. Use {@see format()} for display.
     */
    public function __toString(): string
    {
        return (string) $this->money;
    }
}
