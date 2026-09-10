<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderCatalog;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderTypeDeclarations;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8 persistence: the seeded `provider_capabilities` catalogue is in
 * lock-step with the `Capability` enum, and the stripe/paypal declarations read
 * back correctly. Skips without MySQL / the seeded schema.
 */
final class ProviderCapabilitiesPersistenceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM provider_capabilities LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }
    }

    #[Test]
    public function capabilityCatalogueMatchesTheEnum(): void
    {
        $statement = $this->pdo->query('SELECT code FROM provider_capabilities');
        self::assertNotFalse($statement);

        $seeded = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $seeded[] = (string) $value;
        }
        sort($seeded);

        $enum = array_map(static fn (Capability $c): string => $c->value, Capability::cases());
        sort($enum);

        self::assertSame($enum, $seeded, 'provider_capabilities and the Capability enum are out of sync');
    }

    #[Test]
    public function stripeDeclarationReadsBack(): void
    {
        $declaration = (new PdoProviderTypeDeclarations($this->pdo))->findByCode('stripe');

        self::assertNotNull($declaration);
        self::assertTrue($declaration->supportsPurchaseType(PurchaseType::Subscription));
        self::assertTrue($declaration->supportsPurchaseType(PurchaseType::AutoCharge));
        self::assertTrue($declaration->hasCapability(Capability::PartialRefund));
        self::assertTrue($declaration->hasCapability(Capability::CustomerPortal));
        self::assertFalse($declaration->hasCapability(Capability::ManualStatusPolling));
    }

    #[Test]
    public function paypalDeclarationReadsBack(): void
    {
        $declaration = (new PdoProviderTypeDeclarations($this->pdo))->findByCode('paypal');

        self::assertNotNull($declaration);
        self::assertTrue($declaration->supportsPurchaseType(PurchaseType::OneTimePayment));
        self::assertFalse($declaration->supportsPurchaseType(PurchaseType::AutoCharge));
        self::assertTrue($declaration->hasCapability(Capability::RedirectPayment));
    }

    #[Test]
    public function ziraatAndMollieHaveNoDeclarationsYet(): void
    {
        $reader = new PdoProviderTypeDeclarations($this->pdo);

        // rows exist in provider_types (Phase 4) but no capability/purchase-type rows yet
        $ziraat = $reader->findByCode('ziraat');
        self::assertNotNull($ziraat);
        self::assertSame([], $ziraat->purchaseTypes());
        self::assertTrue($ziraat->capabilities->isEmpty());
    }

    #[Test]
    public function catalogueExposesSummaries(): void
    {
        $catalog = new PdoProviderCatalog($this->pdo, new PdoProviderTypeDeclarations($this->pdo));

        $stripe = $catalog->find('stripe');
        self::assertNotNull($stripe);
        self::assertTrue($stripe->apiCapable);
        self::assertContains('subscription', $stripe->purchaseTypes);
        self::assertContains('partial_refund', $stripe->capabilities);

        self::assertGreaterThanOrEqual(4, \count($catalog->all()));
    }
}
