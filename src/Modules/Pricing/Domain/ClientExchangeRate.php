<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

use DateTimeImmutable;

/**
 * A client-configured FX rate (Phase 13 Q2). `rate` = "1 baseCurrency =
 * rate quoteCurrency", kept as a decimal string to stay out of float land.
 */
final readonly class ClientExchangeRate
{
    public string $baseCurrency;
    public string $quoteCurrency;

    public function __construct(
        public int $clientId,
        string $baseCurrency,
        string $quoteCurrency,
        public string $rate,
        public DateTimeImmutable $effectiveFrom,
    ) {
        $this->baseCurrency = strtoupper($baseCurrency);
        $this->quoteCurrency = strtoupper($quoteCurrency);
    }
}
