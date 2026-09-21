<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

use Gomrok\Shared\Application\ReferenceCatalog;
use PDO;

/**
 * {@see ReferenceCatalog} over the Phase 4 reference tables.
 */
final readonly class PdoReferenceCatalog implements ReferenceCatalog
{
    public function __construct(private PDO $pdo)
    {
    }

    public function currencyExists(string $code): bool
    {
        return $this->exists('SELECT 1 FROM currencies WHERE code = :code', strtoupper($code));
    }

    public function countryExists(string $code): bool
    {
        return $this->exists('SELECT 1 FROM countries WHERE code = :code', strtoupper($code));
    }

    public function listCurrencies(): array
    {
        $statement = $this->pdo->query('SELECT code, name FROM currencies ORDER BY code');
        \assert($statement !== false);

        /** @var list<array{code: string, name: string}> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listCountries(): array
    {
        $statement = $this->pdo->query('SELECT code, name FROM countries ORDER BY code');
        \assert($statement !== false);

        /** @var list<array{code: string, name: string}> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function exists(string $sql, string $code): bool
    {
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute(['code' => $code]);

        return $statement->fetchColumn() !== false;
    }
}
