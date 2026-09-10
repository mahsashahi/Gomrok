<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRate;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRateRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoClientExchangeRateRepository implements ClientExchangeRateRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(ClientExchangeRate $rate): void
    {
        $this->pdo->prepare(
            'INSERT INTO client_exchange_rates
                (client_id, base_currency, quote_currency, rate, effective_from, created_at)
             VALUES (:client_id, :base, :quote, :rate, :effective_from, :now)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)',
        )->execute([
            'client_id' => $rate->clientId,
            'base' => $rate->baseCurrency,
            'quote' => $rate->quoteCurrency,
            'rate' => $rate->rate,
            'effective_from' => $rate->effectiveFrom->format(self::DT),
            'now' => gmdate(self::DT),
        ]);
    }

    public function findRate(int $clientId, string $baseCurrency, string $quoteCurrency, DateTimeImmutable $at): ?ClientExchangeRate
    {
        $statement = $this->pdo->prepare(
            'SELECT rate, effective_from FROM client_exchange_rates
              WHERE client_id = :client_id AND base_currency = :base AND quote_currency = :quote
                AND effective_from <= :at
              ORDER BY effective_from DESC LIMIT 1',
        );
        $statement->execute([
            'client_id' => $clientId,
            'base' => strtoupper($baseCurrency),
            'quote' => strtoupper($quoteCurrency),
            'at' => $at->format(self::DT),
        ]);
        $row = $statement->fetch();

        if (!\is_array($row)) {
            return null;
        }

        return new ClientExchangeRate(
            $clientId,
            $baseCurrency,
            $quoteCurrency,
            Row::str($row['rate'] ?? '0'),
            new DateTimeImmutable(Row::str($row['effective_from'] ?? 'now')),
        );
    }
}
