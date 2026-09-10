<?php

declare(strict_types=1);

namespace Gomrok\Database\Seeds;

use Phinx\Seed\AbstractSeed;
use RuntimeException;

/**
 * Seeds `countries` from `data/countries.json` — the markets Gomrok operates in.
 * Runs after {@see CurrenciesSeeder} (FK on `default_currency`). Idempotent
 * (upsert on `code`).
 */
final class CountriesSeeder extends AbstractSeed
{
    /**
     * @return list<class-string<AbstractSeed>>
     */
    public function getDependencies(): array
    {
        return [CurrenciesSeeder::class];
    }

    public function run(): void
    {
        $pdo = $this->getAdapter()->getConnection();

        $statement = $pdo->prepare(
            'INSERT INTO countries (code, name, default_currency)
             VALUES (:code, :name, :default_currency)
             ON DUPLICATE KEY UPDATE
                 name = VALUES(name),
                 default_currency = VALUES(default_currency)',
        );

        foreach ($this->countries() as $country) {
            $statement->execute([
                'code' => $country['code'],
                'name' => $country['name'],
                'default_currency' => $country['default_currency'],
            ]);
        }
    }

    /**
     * @return list<array{code: string, name: string, default_currency: string}>
     */
    private function countries(): array
    {
        $path = __DIR__ . '/data/countries.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        /** @var list<array{code: string, name: string, default_currency: string}> $rows */
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $rows;
    }
}
