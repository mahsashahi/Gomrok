<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Application;

use Gomrok\Modules\Providers\Application\AddProviderAccountEndpoint\AddProviderAccountEndpointCommand;
use Gomrok\Modules\Providers\Application\AddProviderAccountEndpoint\AddProviderAccountEndpointHandler;
use Gomrok\Modules\Providers\Application\AddProviderAccountEndpoint\AddProviderAccountEndpointResult;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountCommand;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountHandler;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountResult;
use Gomrok\Modules\Providers\Application\RotateProviderAccountSecret\RotateProviderAccountSecretCommand;
use Gomrok\Modules\Providers\Application\RotateProviderAccountSecret\RotateProviderAccountSecretHandler;
use Gomrok\Modules\Providers\Domain\EndpointKind;
use Gomrok\Shared\Domain\ErrorType;
use Gomrok\Shared\Infrastructure\Crypto\SodiumSecretCipher;
use Gomrok\Tests\Support\FixedTokenGenerator;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryProviderAccountRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubClientDirectory;
use Gomrok\Tests\Support\StubProviderCatalog;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderAccountHandlersTest extends TestCase
{
    private const KEY_B64 = 'KioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKio=';

    private InMemoryProviderAccountRepository $accounts;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;
    private SodiumSecretCipher $cipher;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryProviderAccountRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-09T12:00:00+00:00');
        $this->cipher = SodiumSecretCipher::fromBase64Key(self::KEY_B64);
    }

    #[Test]
    public function createsMultipleAccountsOfOneTypeForOneClient(): void
    {
        $live = $this->create(new CreateProviderAccountCommand(7, 'stripe', 'live', 'Live', 'sk_live_aaaabbbbcccc', countries: ['DE'], methods: ['card']));
        $test = $this->create(new CreateProviderAccountCommand(7, 'stripe', 'test', 'Test', 'sk_test_ddddeeeeffff', countries: ['DE'], methods: ['card']));

        self::assertSame('stripe-live', $live->slug);
        self::assertSame('stripe-test', $test->slug);
        self::assertSame('cccc', $live->secretLastFour);
        self::assertNotSame($live->accountId, $test->accountId);

        // secret was encrypted, not stored raw
        $stored = $this->accounts->findByClientAndSlug(7, 'stripe-live');
        self::assertNotNull($stored);
        self::assertNotSame('sk_live_aaaabbbbcccc', $stored->secret()->ciphertext);
        self::assertSame('sk_live_aaaabbbbcccc', $this->cipher->decrypt($stored->secret()->ciphertext));

        self::assertContains('provider_account.created', $this->audit->actions());
    }

    #[Test]
    public function rejectsADuplicateSlug(): void
    {
        $this->create(new CreateProviderAccountCommand(7, 'stripe', 'live', 'Live', 'sk_live_x', slug: 'main'));
        $result = $this->handler()->handle(new CreateProviderAccountCommand(7, 'stripe', 'test', 'Test', 'sk_test_y', slug: 'main'));

        self::assertTrue($result->isErr());
        self::assertSame(ErrorType::Conflict, $result->error()->type);
    }

    #[Test]
    public function rejectsUnknownProviderTypeCountryMethodAndMode(): void
    {
        self::assertSame('provider_account.invalid_mode', $this->err(new CreateProviderAccountCommand(7, 'stripe', 'sandbox', 'X', 'sk')));
        self::assertSame('provider_account.unknown_provider_type', $this->err(new CreateProviderAccountCommand(7, 'adyen', 'live', 'X', 'sk')));
        self::assertSame('provider_account.unknown_method', $this->err(new CreateProviderAccountCommand(7, 'stripe', 'live', 'X', 'sk', methods: ['crypto'])));
        self::assertSame('provider_account.unknown_country', $this->err(new CreateProviderAccountCommand(7, 'stripe', 'live', 'X', 'sk', countries: ['ZZ'])));
    }

    #[Test]
    public function rejectsADisabledClient(): void
    {
        $result = $this->handler(clientActive: false)->handle(new CreateProviderAccountCommand(7, 'stripe', 'live', 'X', 'sk'));

        self::assertTrue($result->isErr());
        self::assertSame(ErrorType::Forbidden, $result->error()->type);
    }

    #[Test]
    public function rotateSecretChangesTheStoredSecretAndAudits(): void
    {
        $created = $this->create(new CreateProviderAccountCommand(7, 'stripe', 'live', 'Live', 'sk_live_original0000'));

        $result = (new RotateProviderAccountSecretHandler($this->accounts, $this->cipher, $this->audit, $this->tx(), $this->clock))
            ->handle(new RotateProviderAccountSecretCommand($created->accountId, 'sk_live_rotated12345'));

        self::assertTrue($result->isOk());
        self::assertSame('2345', $result->value());

        $stored = $this->accounts->findById($created->accountId);
        self::assertNotNull($stored);
        self::assertSame('sk_live_rotated12345', $this->cipher->decrypt($stored->secret()->ciphertext));

        self::assertNotEmpty($this->audit->entries);
        $entry = $this->audit->entries[array_key_last($this->audit->entries)];
        self::assertSame('provider_account.secret_rotated', $entry->action);
        self::assertStringNotContainsString('sk_live_rotated12345', json_encode($entry->after, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('sk_live_original0000', json_encode($entry->before, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function addWebhookEndpointGeneratesTokenAndDeactivatesPrior(): void
    {
        $created = $this->create(new CreateProviderAccountCommand(7, 'stripe', 'live', 'Live', 'sk_live_x'));
        $handler = new AddProviderAccountEndpointHandler(
            $this->accounts,
            new StubProviderCatalog(),
            new FixedTokenGenerator(),
            $this->cipher,
            $this->audit,
            $this->tx(),
            $this->clock,
        );

        $first = $handler->handle(new AddProviderAccountEndpointCommand($created->accountId, 'webhook', 'whsec_1'));
        self::assertTrue($first->isOk());
        $firstPayload = $first->value();
        self::assertInstanceOf(AddProviderAccountEndpointResult::class, $firstPayload);
        self::assertNotNull($firstPayload->token);
        self::assertStringStartsWith('whk_', $firstPayload->token);
        self::assertSame("/api/v1/webhooks/stripe/{$firstPayload->token}", $firstPayload->inboundPath);

        $handler->handle(new AddProviderAccountEndpointCommand($created->accountId, 'webhook', 'whsec_2'));

        $account = $this->accounts->findById($created->accountId);
        self::assertNotNull($account);
        $active = $account->activeEndpoint(EndpointKind::Webhook);
        self::assertNotNull($active);
        self::assertSame($this->cipher->decrypt((string) $active->signingSecretCiphertext()), 'whsec_2');

        // a return endpoint gets no token
        $return = $handler->handle(new AddProviderAccountEndpointCommand($created->accountId, 'return'));
        $returnPayload = $return->value();
        self::assertInstanceOf(AddProviderAccountEndpointResult::class, $returnPayload);
        self::assertNull($returnPayload->token);
    }

    private function create(CreateProviderAccountCommand $command): CreateProviderAccountResult
    {
        $result = $this->handler()->handle($command);
        $payload = $result->value();
        self::assertInstanceOf(CreateProviderAccountResult::class, $payload);

        return $payload;
    }

    private function err(CreateProviderAccountCommand $command): string
    {
        $result = $this->handler()->handle($command);
        self::assertTrue($result->isErr());

        return $result->error()->code;
    }

    private function handler(bool $clientActive = true): CreateProviderAccountHandler
    {
        return new CreateProviderAccountHandler(
            $this->accounts,
            new StubClientDirectory(clientId: 7, active: $clientActive),
            new StubProviderCatalog(),
            new InMemoryReferenceCatalog(),
            $this->cipher,
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
