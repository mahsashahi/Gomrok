<?php

declare(strict_types=1);

namespace Gomrok\Database\Seeds;

use PDO;
use Phinx\Seed\AbstractSeed;
use RuntimeException;

/**
 * Seeds `provider_type_capabilities` / `provider_type_purchase_types` from
 * `data/ProviderTypeDeclarations.json`. Stripe + PayPal since Phase 8 (Q5);
 * Ziraat + Mollie added in Phase 10 so the routing exit criteria (Turkey =
 * one-time-only via Ziraat, Germany via Mollie) have real capability data.
 * Idempotent (upsert on the unique keys).
 */
final class ProviderTypeDeclarationsSeeder extends AbstractSeed
{
    /**
     * @return list<class-string<AbstractSeed>>
     */
    public function getDependencies(): array
    {
        return [ProviderTypesSeeder::class, ProviderCapabilitiesSeeder::class];
    }

    public function run(): void
    {
        $pdo = $this->getAdapter()->getConnection();

        $providerTypeId = $this->lookup($pdo, 'SELECT id FROM provider_types WHERE code = :code');
        $capabilityId = $this->lookup($pdo, 'SELECT id FROM provider_capabilities WHERE code = :code');

        $insertCapability = $pdo->prepare(
            'INSERT INTO provider_type_capabilities (provider_type_id, capability_id)
             VALUES (:provider_type_id, :capability_id)
             ON DUPLICATE KEY UPDATE provider_type_id = provider_type_id',
        );
        $insertPurchaseType = $pdo->prepare(
            'INSERT INTO provider_type_purchase_types (provider_type_id, purchase_type)
             VALUES (:provider_type_id, :purchase_type)
             ON DUPLICATE KEY UPDATE provider_type_id = provider_type_id',
        );

        foreach ($this->declarations() as $declaration) {
            $typeId = $providerTypeId($declaration['type']);

            foreach ($declaration['capabilities'] as $code) {
                $insertCapability->execute([
                    'provider_type_id' => $typeId,
                    'capability_id' => $capabilityId($code),
                ]);
            }

            foreach ($declaration['purchase_types'] as $purchaseType) {
                $insertPurchaseType->execute([
                    'provider_type_id' => $typeId,
                    'purchase_type' => $purchaseType,
                ]);
            }
        }
    }

    /**
     * @return callable(string): int
     */
    private function lookup(PDO $pdo, string $sql): callable
    {
        $statement = $pdo->prepare($sql);

        return static function (string $code) use ($statement, $sql): int {
            $statement->execute(['code' => $code]);
            $id = $statement->fetchColumn();
            if ($id === false) {
                throw new RuntimeException("No row for code '{$code}' ({$sql}).");
            }

            return (int) $id;
        };
    }

    /**
     * @return list<array{type: string, purchase_types: list<string>, capabilities: list<string>}>
     */
    private function declarations(): array
    {
        $path = __DIR__ . '/data/ProviderTypeDeclarations.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        /** @var list<array{type: string, purchase_types: list<string>, capabilities: list<string>}> $rows */
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $rows;
    }
}
