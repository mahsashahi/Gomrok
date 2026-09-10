<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Database;

use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the seed data for `provider_type_capabilities` / `provider_type_purchase_types`
 * — every code in `ProviderTypeDeclarations.json` must be a real enum value, so
 * a typo is caught without a database. Phase 10 added ziraat + mollie.
 */
final class ProviderTypeDeclarationsDataTest extends TestCase
{
    #[Test]
    public function everyCodeInTheSeedFileIsAValidEnumValue(): void
    {
        $path = \dirname(__DIR__, 3) . '/src/Database/Seeds/data/ProviderTypeDeclarations.json';
        $json = file_get_contents($path);
        self::assertIsString($json);

        /** @var list<array{type: string, purchase_types: list<string>, capabilities: list<string>}> $rows */
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertNotEmpty($rows);

        foreach ($rows as $row) {
            self::assertContains($row['type'], ['stripe', 'paypal', 'mollie', 'ziraat'], 'unknown provider type in the seed file');

            foreach ($row['purchase_types'] as $purchaseType) {
                self::assertNotNull(PurchaseType::tryFrom($purchaseType), "unknown purchase_type '{$purchaseType}' for {$row['type']}");
            }
            foreach ($row['capabilities'] as $capability) {
                self::assertNotNull(Capability::tryFrom($capability), "unknown capability '{$capability}' for {$row['type']}");
            }
        }
    }
}
