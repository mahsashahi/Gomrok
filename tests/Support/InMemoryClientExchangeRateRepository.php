<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRate;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRateRepository;

final class InMemoryClientExchangeRateRepository implements ClientExchangeRateRepository
{
    /** @var list<ClientExchangeRate> */
    private array $rates = [];

    public function save(ClientExchangeRate $rate): void
    {
        $this->rates[] = $rate;
    }

    public function findRate(int $clientId, string $baseCurrency, string $quoteCurrency, DateTimeImmutable $at): ?ClientExchangeRate
    {
        $matches = array_filter(
            $this->rates,
            static fn (ClientExchangeRate $r): bool => $r->clientId === $clientId
                && $r->baseCurrency === strtoupper($baseCurrency)
                && $r->quoteCurrency === strtoupper($quoteCurrency)
                && $r->effectiveFrom <= $at,
        );
        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (ClientExchangeRate $a, ClientExchangeRate $b): int => $b->effectiveFrom <=> $a->effectiveFrom);

        return $matches[0];
    }
}
