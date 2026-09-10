<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use DI\Container;
use Gomrok\Bootstrap\AppFactory;
use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Packages\Domain\PackagePurchaseCapability;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePriceRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackageRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Application\ErrorLog\ErrorLogWriter;
use Gomrok\Shared\Application\Idempotency\IdempotencyStore;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientAuthenticator;
use Gomrok\Shared\Infrastructure\Persistence\NullErrorLogWriter;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryIdempotencyStore;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\StubClientAuthenticator;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PackagesApiTest extends TestCase
{
    private const CLIENT = 7;

    #[Test]
    public function packagesEndpointReturnsThePricedCatalogue(): void
    {
        $response = $this->handle('GET', '/api/v1/packages?country=DE');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{currency: string, packages: list<array{code: string, price: array{amount_minor: int, currency: string}}>} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('EUR', $body['currency']);
        self::assertSame('pro', $body['packages'][0]['code']);
        self::assertSame(2900, $body['packages'][0]['price']['amount_minor']);
    }

    #[Test]
    public function packagesEndpointRequiresACountry(): void
    {
        $response = $this->handle('GET', '/api/v1/packages');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function pricingResolveEndpointReturnsOnePrice(): void
    {
        $response = $this->handle('GET', '/api/v1/pricing/resolve?package=pro&country=DE');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{package: string, price: array{amount_minor: int, source: string}} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pro', $body['package']);
        self::assertSame(2900, $body['price']['amount_minor']);
        self::assertSame('baseline', $body['price']['source']);
    }

    private function handle(string $method, string $path): ResponseInterface
    {
        $now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $container = ContainerFactory::create();
        self::assertInstanceOf(Container::class, $container);

        $client = new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'UTC', 'gk_live');
        $container->set(ClientAuthenticator::class, StubClientAuthenticator::succeedingAs($client));
        $container->set(IdempotencyStore::class, new InMemoryIdempotencyStore());
        $container->set(ErrorLogWriter::class, new NullErrorLogWriter());

        $packageRepo = new InMemoryPackageRepository();
        $pro = Package::create(self::CLIENT, PackageCode::of('pro'), 'Pro', null, null, $now);
        $pro->setPurchaseCapabilities([PackagePurchaseCapability::of(PurchaseType::OneTimePayment)], $now);
        $packageRepo->save($pro);
        $proId = $pro->id();
        self::assertNotNull($proId);

        $container->set(PackageRepository::class, $packageRepo);
        $container->set(ProviderAccountDirectory::class, new StubProviderAccountDirectory());
        $container->set(PackageDirectory::class, (new StubPackageDirectory())->add($proId, self::CLIENT, 'pro', 'Pro'));

        $groups = new InMemoryPricingGroupRepository();
        $groups->save(PricingGroup::define(self::CLIENT, PricingGroupSlug::of('default'), 'Default', 0, null, 'EUR', true, $now));
        $container->set(PricingGroupRepository::class, $groups);
        $container->set(PricingGroupPackageRepository::class, new InMemoryPricingGroupPackageRepository());

        $defaults = new InMemoryDefaultPackagePriceRepository();
        $defaults->save(new DefaultPackagePrice($proId, 2900, 'EUR'));
        $container->set(DefaultPackagePriceRepository::class, $defaults);
        $container->set(\Gomrok\Modules\Pricing\Domain\ClientExchangeRateRepository::class, new InMemoryClientExchangeRateRepository());

        $app = AppFactory::create($container);

        return $app->handle((new ServerRequestFactory())->createServerRequest($method, $path));
    }
}
