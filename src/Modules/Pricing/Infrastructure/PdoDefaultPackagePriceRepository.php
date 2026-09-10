<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePriceRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoDefaultPackagePriceRepository implements DefaultPackagePriceRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(DefaultPackagePrice $price): void
    {
        $now = gmdate(self::DT);
        $this->pdo->prepare(
            'INSERT INTO default_package_prices (package_id, amount_minor, currency_code, created_at, updated_at)
             VALUES (:package_id, :amount_minor, :currency_code, :now, :now)
             ON DUPLICATE KEY UPDATE amount_minor = VALUES(amount_minor), currency_code = VALUES(currency_code), updated_at = VALUES(updated_at)',
        )->execute([
            'package_id' => $price->packageId,
            'amount_minor' => $price->amountMinor,
            'currency_code' => $price->currencyCode,
            'now' => $now,
        ]);
    }

    public function find(int $packageId): ?DefaultPackagePrice
    {
        $statement = $this->pdo->prepare('SELECT amount_minor, currency_code FROM default_package_prices WHERE package_id = :id');
        $statement->execute(['id' => $packageId]);
        $row = $statement->fetch();

        if (!\is_array($row)) {
            return null;
        }

        return new DefaultPackagePrice(
            $packageId,
            Row::int($row['amount_minor'] ?? null),
            Row::str($row['currency_code'] ?? ''),
        );
    }
}
