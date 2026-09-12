<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Infrastructure;

use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use Gomrok\Modules\Providers\Infrastructure\Adapter\Stripe\StripeAdapter;
use Gomrok\Modules\Providers\Infrastructure\DefaultProviderAdapterFactory;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Gomrok\Tests\Support\StubProviderAccountCredentials;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DefaultProviderAdapterFactoryTest extends TestCase
{
    #[Test]
    public function buildsAStripeAdapterForAStripeAccount(): void
    {
        $accounts = (new StubProviderAccountDirectory())->add(1, 7, 'stripe-live', 'stripe');
        $credentials = (new StubProviderAccountCredentials())->withSecret(1, 'sk_test_fake');
        $factory = new DefaultProviderAdapterFactory($accounts, $credentials, InMemoryProviderTypeDeclarations::withKnownProviders());

        $adapter = $factory->for(1);

        self::assertInstanceOf(StripeAdapter::class, $adapter);
    }

    #[Test]
    public function rejectsAProviderTypeWithNoAdapterYet(): void
    {
        $accounts = (new StubProviderAccountDirectory())->add(2, 7, 'ziraat-live', 'ziraat');
        $credentials = (new StubProviderAccountCredentials())->withSecret(2, 'some-secret');
        $factory = new DefaultProviderAdapterFactory($accounts, $credentials, InMemoryProviderTypeDeclarations::withKnownProviders());

        $this->expectException(UnsupportedProviderType::class);
        $factory->for(2);
    }

    #[Test]
    public function rejectsAnUnknownAccount(): void
    {
        $factory = new DefaultProviderAdapterFactory(
            new StubProviderAccountDirectory(),
            new StubProviderAccountCredentials(),
            InMemoryProviderTypeDeclarations::withKnownProviders(),
        );

        $this->expectException(RuntimeException::class);
        $factory->for(999);
    }
}
