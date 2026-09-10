<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetClientExchangeRate;

/**
 * Set (upsert) a client FX rate. `rate` = "1 base = rate quote", a decimal
 * string. `effectiveFrom` is an ISO-8601 datetime; omitted = now.
 */
final readonly class SetClientExchangeRateCommand
{
    public function __construct(
        public int $clientId,
        public string $baseCurrency,
        public string $quoteCurrency,
        public string $rate,
        public ?string $effectiveFrom = null,
        public ?int $actorId = null,
    ) {
    }
}
