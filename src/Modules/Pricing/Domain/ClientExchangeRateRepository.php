<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

use DateTimeImmutable;

/**
 * Persistence port for {@see ClientExchangeRate}.
 */
interface ClientExchangeRateRepository
{
    /** Insert or update (unique on client + base + quote + effective_from). */
    public function save(ClientExchangeRate $rate): void;

    /**
     * The most recent rate for the pair with `effective_from <= $at`, or null.
     */
    public function findRate(int $clientId, string $baseCurrency, string $quoteCurrency, DateTimeImmutable $at): ?ClientExchangeRate;
}
