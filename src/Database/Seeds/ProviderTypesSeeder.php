<?php

declare(strict_types=1);

namespace Gomrok\Database\Seeds;

use Phinx\Seed\AbstractSeed;

/**
 * Seeds `provider_types` with the providers Gomrok integrates with.
 * Idempotent (upsert on `code`).
 *
 * `ziraat` is seeded as a **planned/deferred** provider type — the capability
 * catalogue and country-routing rules (Phase 10) already reference it, but no
 * `ZiraatAdapter` exists (Phase 23's decision, `PhaseResults/PhaseDecisions.md`):
 * Ziraat integration is deferred until official documentation and credentials
 * are available. Do not add adapter-specific fields here for it.
 */
final class ProviderTypesSeeder extends AbstractSeed
{
    /**
     * @var list<array{code: string, name: string, requires_registration: bool, api_capable: bool}>
     */
    private const TYPES = [
        ['code' => 'stripe', 'name' => 'Stripe', 'requires_registration' => true, 'api_capable' => true],
        ['code' => 'mollie', 'name' => 'Mollie', 'requires_registration' => true, 'api_capable' => true],
        ['code' => 'paypal', 'name' => 'PayPal', 'requires_registration' => true, 'api_capable' => true],
        // Deferred — no adapter yet, see the class docblock.
        ['code' => 'ziraat', 'name' => 'Ziraat Bank', 'requires_registration' => false, 'api_capable' => false],
    ];

    public function run(): void
    {
        $pdo = $this->getAdapter()->getConnection();

        $statement = $pdo->prepare(
            'INSERT INTO provider_types (code, name, requires_registration, api_capable)
             VALUES (:code, :name, :requires_registration, :api_capable)
             ON DUPLICATE KEY UPDATE
                 name = VALUES(name),
                 requires_registration = VALUES(requires_registration),
                 api_capable = VALUES(api_capable)',
        );

        foreach (self::TYPES as $type) {
            $statement->execute([
                'code' => $type['code'],
                'name' => $type['name'],
                'requires_registration' => (int) $type['requires_registration'],
                'api_capable' => (int) $type['api_capable'],
            ]);
        }
    }
}
