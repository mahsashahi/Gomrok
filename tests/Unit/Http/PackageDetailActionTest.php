<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use Gomrok\Http\Api\PackageDetailAction;
use Gomrok\Modules\Packages\Application\PackageCatalog;
use Gomrok\Modules\Packages\Application\PackagePurchaseCapabilityResolver;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Packages\Domain\PackagePurchaseCapability;
use Gomrok\Modules\Pricing\Application\PriceCatalog;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListRepository;
use Gomrok\Tests\Support\InMemoryPriceRuleRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PackageDetailActionTest extends TestCase
{
    private const CLIENT = 7;
    private const OTHER_CLIENT = 9;

    #[Test]
    public function returnsThePackageByCode(): void
    {
        $action = $this->action($proId = $this->seed());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/packages/pro?country=DE'),
            (new ResponseFactory())->createResponse(),
            ['packageId' => 'pro'],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string, id: int, price: array{amount_minor: int}} $body */
        self::assertSame('pro', $body['code']);
        self::assertSame($proId, $body['id']);
        self::assertSame(2900, $body['price']['amount_minor']);
    }

    #[Test]
    public function returnsThePackageByNumericId(): void
    {
        $proId = $this->seed();
        $action = $this->action($proId);

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', "/api/v1/packages/{$proId}?country=DE"),
            (new ResponseFactory())->createResponse(),
            ['packageId' => (string) $proId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('pro', $body['code']);
    }

    #[Test]
    public function requiresACountry(): void
    {
        $action = $this->action($this->seed());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/packages/pro'),
            (new ResponseFactory())->createResponse(),
            ['packageId' => 'pro'],
        );

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('packages.country_required', $body['code']);
    }

    #[Test]
    public function anotherClientsPackageIsNotFoundEvenByNumericId(): void
    {
        $proId = $this->seed();
        $this->directory->add($proId + 100, self::OTHER_CLIENT, 'rival');
        $action = $this->action($proId);

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/packages/' . ($proId + 100) . '?country=DE'),
            (new ResponseFactory())->createResponse(),
            ['packageId' => (string) ($proId + 100)],
        );

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('package.not_found', $body['code']);
    }

    #[Test]
    public function unknownCodeIs404(): void
    {
        $action = $this->action($this->seed());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/packages/ghost?country=DE'),
            (new ResponseFactory())->createResponse(),
            ['packageId' => 'ghost'],
        );

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('package.not_found', $body['code']);
    }

    #[Test]
    public function unavailableInTheRequestedCountryIs404NotFoundInContext(): void
    {
        $action = $this->action($this->seed());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/packages/pro?country=US'),
            (new ResponseFactory())->createResponse(),
            ['packageId' => 'pro'],
        );

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('package.not_found_in_context', $body['code']);
    }

    private InMemoryPackageRepository $packages;
    private StubPackageDirectory $directory;

    /**
     * Seeds one package ("pro", EUR 29.00, sold only in a DE-only pricing
     * group) and returns its id.
     */
    private function seed(): int
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
        $this->packages = new InMemoryPackageRepository();
        $this->directory = new StubPackageDirectory();

        $package = Package::create(self::CLIENT, PackageCode::of('pro'), 'Pro', null, null, $now);
        $package->setPurchaseCapabilities([PackagePurchaseCapability::of(PurchaseType::OneTimePayment)], $now);
        // Available in DE only (fail-open dimension — a non-empty set restricts), so a US
        // request resolves a valid pricing group but still can't find this package.
        $package->setAvailability(['DE'], [], [], [], $now);
        $this->packages->save($package);
        $id = $package->id();
        \assert($id !== null);
        $this->directory->add($id, self::CLIENT, 'pro', 'Pro');

        $groups = new InMemoryPricingGroupRepository();
        // Default group, no country restriction of its own — resolves for any country.
        $group = PricingGroup::define(self::CLIENT, PricingGroupSlug::of('default'), 'Default', 0, null, 'EUR', true, $now);
        $groups->save($group);

        $defaults = new InMemoryDefaultPackagePriceRepository();
        $defaults->save(new DefaultPackagePrice($id, 2900, 'EUR'));

        $this->priceResolver = new PriceResolver(
            $groups,
            new InMemoryPricingGroupPackageRepository(),
            $defaults,
            new InMemoryClientExchangeRateRepository(),
            $this->directory,
            new PriceListResolver(new InMemoryPriceListRepository(), new InMemoryPriceListPackageRepository()),
            new PriceRuleResolver(new InMemoryPriceRuleRepository()),
            new FrozenClock('2026-09-11T12:00:00+00:00'),
        );

        return $id;
    }

    private PriceResolver $priceResolver;

    private function action(int $seededPackageId): PackageDetailAction
    {
        $context = new ClientContext();
        $context->set(new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'gk_test'));

        $packageCatalog = new PackageCatalog($this->packages, new StubProviderAccountDirectory(), new PackagePurchaseCapabilityResolver($this->packages));
        $catalog = new PriceCatalog($packageCatalog, $this->priceResolver);

        return new PackageDetailAction($context, $this->directory, $catalog, new JsonResponder());
    }
}
