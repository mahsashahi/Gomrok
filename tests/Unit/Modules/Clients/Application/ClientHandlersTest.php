<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Clients\Application;

use Gomrok\Modules\Clients\Application\CreateClient\CreateClientCommand;
use Gomrok\Modules\Clients\Application\CreateClient\CreateClientHandler;
use Gomrok\Modules\Clients\Application\CreateClient\CreateClientResult;
use Gomrok\Modules\Clients\Application\DisableClient\DisableClientCommand;
use Gomrok\Modules\Clients\Application\DisableClient\DisableClientHandler;
use Gomrok\Modules\Clients\Application\EnableClient\EnableClientCommand;
use Gomrok\Modules\Clients\Application\EnableClient\EnableClientHandler;
use Gomrok\Modules\Clients\Application\IssueApiKey\IssueApiKeyCommand;
use Gomrok\Modules\Clients\Application\IssueApiKey\IssueApiKeyHandler;
use Gomrok\Modules\Clients\Application\IssueApiKey\IssueApiKeyResult;
use Gomrok\Modules\Clients\Application\RevokeApiKey\RevokeApiKeyCommand;
use Gomrok\Modules\Clients\Application\RevokeApiKey\RevokeApiKeyHandler;
use Gomrok\Modules\Clients\Application\SetClientEndpoint\SetClientEndpointCommand;
use Gomrok\Modules\Clients\Application\SetClientEndpoint\SetClientEndpointHandler;
use Gomrok\Modules\Clients\Domain\ApiKeyStatus;
use Gomrok\Modules\Clients\Domain\ClientStatus;
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

final class ClientHandlersTest extends TestCase
{
    private InMemoryClientRepository $clients;
    private InMemoryClientApiKeyRepository $apiKeys;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clients = new InMemoryClientRepository();
        $this->apiKeys = new InMemoryClientApiKeyRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-08T12:00:00+00:00');
    }

    #[Test]
    public function disableThenEnableRoundTrip(): void
    {
        $id = $this->createClient('televika');

        $disabled = (new DisableClientHandler($this->clients, $this->audit, $this->tx(), $this->clock))
            ->handle(new DisableClientCommand($id, 'billing hold', 3));
        self::assertTrue($disabled->isOk());
        self::assertSame(ClientStatus::Disabled, $this->clients->findById($id)?->status());

        // idempotent
        (new DisableClientHandler($this->clients, $this->audit, $this->tx(), $this->clock))
            ->handle(new DisableClientCommand($id));

        $enabled = (new EnableClientHandler($this->clients, $this->audit, $this->tx(), $this->clock))
            ->handle(new EnableClientCommand($id));
        self::assertTrue($enabled->isOk());
        self::assertSame(ClientStatus::Active, $this->clients->findById($id)?->status());

        self::assertSame(
            ['client.created', 'client.disabled', 'client.enabled'],
            $this->audit->actions(),
        );
    }

    #[Test]
    public function disableUnknownClientIsNotFound(): void
    {
        $result = (new DisableClientHandler($this->clients, $this->audit, $this->tx(), $this->clock))
            ->handle(new DisableClientCommand(999));

        self::assertTrue($result->isErr());
        self::assertSame(ErrorType::NotFound, $result->error()->type);
    }

    #[Test]
    public function issuingAKeyForADisabledClientIsForbidden(): void
    {
        $id = $this->createClient('televika');
        (new DisableClientHandler($this->clients, $this->audit, $this->tx(), $this->clock))
            ->handle(new DisableClientCommand($id));

        $result = $this->issueHandler()->handle(new IssueApiKeyCommand($id));

        self::assertTrue($result->isErr());
        self::assertSame(ErrorType::Forbidden, $result->error()->type);
        self::assertSame('client.disabled', $result->error()->code);
    }

    #[Test]
    public function issueThenRevokeAKey(): void
    {
        $id = $this->createClient('televika');

        $issued = $this->issueHandler()->handle(new IssueApiKeyCommand($id));
        self::assertTrue($issued->isOk());
        $payload = $issued->value();
        self::assertInstanceOf(IssueApiKeyResult::class, $payload);

        self::assertSame(2, $this->apiKeys->countActiveForClient($id)); // first key + this one

        $revoked = (new RevokeApiKeyHandler($this->apiKeys, $this->audit, $this->tx(), $this->clock))
            ->handle(new RevokeApiKeyCommand($payload->keyId, 4));
        self::assertTrue($revoked->isOk());
        self::assertSame(ApiKeyStatus::Revoked, $this->apiKeys->findByKeyId($payload->keyId)?->status());
        self::assertSame(1, $this->apiKeys->countActiveForClient($id));

        // idempotent
        $again = (new RevokeApiKeyHandler($this->apiKeys, $this->audit, $this->tx(), $this->clock))
            ->handle(new RevokeApiKeyCommand($payload->keyId));
        self::assertTrue($again->isOk());
    }

    #[Test]
    public function revokeUnknownKeyIsNotFound(): void
    {
        $result = (new RevokeApiKeyHandler($this->apiKeys, $this->audit, $this->tx(), $this->clock))
            ->handle(new RevokeApiKeyCommand('nope'));

        self::assertTrue($result->isErr());
        self::assertSame('client.api_key_not_found', $result->error()->code);
    }

    #[Test]
    public function setEndpointValidatesTheUrlAndPurpose(): void
    {
        $id = $this->createClient('televika');
        $handler = new SetClientEndpointHandler($this->clients, $this->audit, $this->tx(), $this->clock);

        self::assertTrue($handler->handle(new SetClientEndpointCommand($id, 'nope', 'https://x.example'))->isErr());
        self::assertTrue($handler->handle(new SetClientEndpointCommand($id, 'payment_status', 'http://insecure.example'))->isErr());

        $ok = $handler->handle(new SetClientEndpointCommand($id, 'payment_status', 'https://x.example/hook'));
        self::assertTrue($ok->isOk());
        self::assertCount(1, $this->clients->findById($id)?->endpoints() ?? []);
    }

    private function createClient(string $slug): int
    {
        $result = $this->createHandler()->handle(new CreateClientCommand($slug, ucfirst($slug), 'EUR', 'DE'));
        $payload = $result->value();
        self::assertInstanceOf(CreateClientResult::class, $payload);

        return $payload->clientId;
    }

    private function createHandler(): CreateClientHandler
    {
        return new CreateClientHandler(
            $this->clients,
            $this->apiKeys,
            new RandomApiKeyGenerator(new RandomTokenGenerator()),
            new FixedTokenGenerator(),
            new InMemoryReferenceCatalog(),
            $this->audit,
            $this->tx(),
            $this->clock,
        );
    }

    private function issueHandler(): IssueApiKeyHandler
    {
        return new IssueApiKeyHandler(
            $this->clients,
            $this->apiKeys,
            new RandomApiKeyGenerator(new RandomTokenGenerator()),
            $this->audit,
            $this->tx(),
            $this->clock,
        );
    }

    private function tx(): SynchronousTransactions
    {
        return new SynchronousTransactions();
    }
}
