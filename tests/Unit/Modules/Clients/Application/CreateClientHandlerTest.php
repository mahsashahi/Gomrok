<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Clients\Application;

use Gomrok\Modules\Clients\Application\CreateClient\CreateClientCommand;
use Gomrok\Modules\Clients\Application\CreateClient\CreateClientHandler;
use Gomrok\Modules\Clients\Application\CreateClient\CreateClientResult;
use Gomrok\Modules\Clients\Domain\ApiKeyToken;
use Gomrok\Modules\Clients\Infrastructure\RandomApiKeyGenerator;
use Gomrok\Shared\Domain\ErrorType;
use Gomrok\Shared\Infrastructure\RandomTokenGenerator;
use Gomrok\Tests\Support\FixedTokenGenerator;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientApiKeyRepository;
use Gomrok\Tests\Support\InMemoryClientRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateClientHandlerTest extends TestCase
{
    private InMemoryClientRepository $clients;
    private InMemoryClientApiKeyRepository $apiKeys;
    private RecordingAuditLogWriter $audit;
    private CreateClientHandler $handler;

    protected function setUp(): void
    {
        $this->clients = new InMemoryClientRepository();
        $this->apiKeys = new InMemoryClientApiKeyRepository();
        $this->audit = new RecordingAuditLogWriter();

        $this->handler = new CreateClientHandler(
            $this->clients,
            $this->apiKeys,
            new RandomApiKeyGenerator(new RandomTokenGenerator()),
            new FixedTokenGenerator(),
            new InMemoryReferenceCatalog(),
            $this->audit,
            new SynchronousTransactions(),
            new FrozenClock('2026-09-08T12:00:00+00:00'),
        );
    }

    #[Test]
    public function createsAClientAndItsFirstKey(): void
    {
        $result = $this->handler->handle(new CreateClientCommand('televika', 'Televika', 'EUR', 'DE'));

        self::assertTrue($result->isOk());
        $payload = $result->value();
        self::assertInstanceOf(CreateClientResult::class, $payload);

        $client = $this->clients->findBySlug('televika');
        self::assertNotNull($client);
        self::assertSame($payload->clientId, $client->id());
        self::assertSame('EUR', $client->defaultCurrency()->code());

        // the returned token parses and matches the stored key
        $token = ApiKeyToken::parse($payload->plaintextApiKey);
        self::assertNotNull($token);
        $stored = $this->apiKeys->findByKeyId($payload->keyId);
        self::assertNotNull($stored);
        self::assertTrue($stored->matchesSecret($token->secret));

        self::assertContains('client.created', $this->audit->actions());
    }

    #[Test]
    public function rejectsAnInvalidSlug(): void
    {
        $result = $this->handler->handle(new CreateClientCommand('Bad Slug', 'X', 'EUR'));

        self::assertTrue($result->isErr());
        self::assertSame('client.invalid_slug', $result->error()->code);
    }

    #[Test]
    public function rejectsADuplicateSlug(): void
    {
        $this->handler->handle(new CreateClientCommand('televika', 'Televika', 'EUR'));
        $result = $this->handler->handle(new CreateClientCommand('televika', 'Televika 2', 'EUR'));

        self::assertTrue($result->isErr());
        self::assertSame(ErrorType::Conflict, $result->error()->type);
        self::assertSame('client.slug_taken', $result->error()->code);
    }

    #[Test]
    public function rejectsACurrencyThatIsNotAConfiguredMarket(): void
    {
        $result = $this->handler->handle(new CreateClientCommand('acme', 'Acme', 'CHF'));

        self::assertTrue($result->isErr());
        self::assertSame('client.unknown_currency', $result->error()->code);
    }

    #[Test]
    public function rejectsAMalformedCountryCode(): void
    {
        $result = $this->handler->handle(new CreateClientCommand('acme', 'Acme', 'EUR', 'z1'));

        self::assertTrue($result->isErr());
        self::assertSame('client.invalid_market', $result->error()->code);
    }

    #[Test]
    public function rejectsACountryThatIsWellFormedButNotAMarket(): void
    {
        $result = $this->handler->handle(new CreateClientCommand('acme', 'Acme', 'EUR', 'FR'));

        self::assertTrue($result->isErr());
        self::assertSame('client.unknown_country', $result->error()->code);
    }
}
