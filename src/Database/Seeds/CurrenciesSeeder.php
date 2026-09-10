<?php

declare(strict_types=1);

namespace Gomrok\Database\Seeds;

use Brick\Money\ISOCurrencyProvider;
use Phinx\Seed\AbstractSeed;

/**
 * Seeds `currencies` with the full ISO 4217 set from brick/money — the same
 * source `Currency` / `Money` validate against. Idempotent (upsert on `code`).
 */
final class CurrenciesSeeder extends AbstractSeed
{
    public function run(): void
    {
        $pdo = $this->getAdapter()->getConnection();

        $statement = $pdo->prepare(
            'INSERT INTO currencies (code, numeric_code, name, minor_unit_scale)
             VALUES (:code, :numeric_code, :name, :minor_unit_scale)
             ON DUPLICATE KEY UPDATE
                 numeric_code = VALUES(numeric_code),
                 name = VALUES(name),
                 minor_unit_scale = VALUES(minor_unit_scale)',
        );

        foreach (ISOCurrencyProvider::getInstance()->getAvailableCurrencies() as $currency) {
            $statement->execute([
                'code' => $currency->getCurrencyCode(),
                'numeric_code' => $currency->getNumericCode(),
                'name' => $currency->getName(),
                'minor_unit_scale' => $currency->getDefaultFractionDigits(),
            ]);
        }
    }
}
