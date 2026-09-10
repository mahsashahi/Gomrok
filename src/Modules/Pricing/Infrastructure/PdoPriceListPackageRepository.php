<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use Gomrok\Modules\Pricing\Domain\PriceListPackage;
use Gomrok\Modules\Pricing\Domain\PriceListPackageRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPriceListPackageRepository implements PriceListPackageRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(PriceListPackage $row): void
    {
        $now = gmdate(self::DT);
        $statement = $this->pdo->prepare(
            'INSERT INTO price_list_packages (price_list_id, package_id, amount_minor, currency_code, created_at, updated_at)
             VALUES (:l, :p, :a, :c, :now, :now)
             ON DUPLICATE KEY UPDATE amount_minor = VALUES(amount_minor), currency_code = VALUES(currency_code), updated_at = VALUES(updated_at)',
        );
        $statement->execute([
            'l' => $row->priceListId,
            'p' => $row->packageId,
            'a' => $row->amountMinor,
            'c' => $row->currencyCode,
            'now' => $now,
        ]);
    }

    public function find(int $priceListId, int $packageId): ?PriceListPackage
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_list_packages WHERE price_list_id = :l AND package_id = :p');
        $statement->execute(['l' => $priceListId, 'p' => $packageId]);

        return $this->hydrate($statement->fetch());
    }

    public function delete(int $priceListId, int $packageId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM price_list_packages WHERE price_list_id = :l AND package_id = :p');
        $statement->execute(['l' => $priceListId, 'p' => $packageId]);

        return $statement->rowCount() > 0;
    }

    public function forList(int $priceListId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_list_packages WHERE price_list_id = :l ORDER BY package_id ASC');
        $statement->execute(['l' => $priceListId]);

        $rows = [];
        while (($row = $statement->fetch()) !== false) {
            $hydrated = $this->hydrate($row);
            if ($hydrated !== null) {
                $rows[] = $hydrated;
            }
        }

        return $rows;
    }

    private function hydrate(mixed $row): ?PriceListPackage
    {
        if (!\is_array($row)) {
            return null;
        }

        return new PriceListPackage(
            Row::int($row['price_list_id'] ?? null),
            Row::int($row['package_id'] ?? null),
            Row::int($row['amount_minor'] ?? null),
            Row::str($row['currency_code'] ?? ''),
        );
    }
}
