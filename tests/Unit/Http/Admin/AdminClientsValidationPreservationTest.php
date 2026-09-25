<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http\Admin;

use DateTimeImmutable;
use Gomrok\Http\Admin\AdminClientsAction;
use Gomrok\Http\Admin\AdminClientsCreateAction;
use Gomrok\Http\Admin\AdminClientsUpdateAction;
use Gomrok\Modules\Admin\Application\Clients\ClientsScreenHandler;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Application\CreateClient\CreateClientHandler;
use Gomrok\Modules\Clients\Application\UpdateClient\UpdateClientHandler;
use Gomrok\Modules\Clients\Domain\ApiKeyGenerator;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Modules\Clients\Domain\Client;
use Gomrok\Modules\Clients\Domain\ClientSlug;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Clients\Domain\GeneratedApiKey;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AuthenticatedAdmin;
use Gomrok\Tests\Support\FixedTokenGenerator;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientApiKeyRepository;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryClientRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RealAdminViewRenderer;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The validation-preserving forms rule (`.claude/docs/Ui.md`), exercised
 * end-to-end through the real `clients.html.twig` template: a failed create
 * or edit submission must re-render the same screen (not redirect), with the
 * modal reopened and every submitted value still in place.
 */
final class AdminClientsValidationPreservationTest extends TestCase
{
    private AdminContext $context;
    private InMemoryClientRepository $clients;
    private InMemoryClientDirectory $clientDirectory;
    private InMemoryClientApiKeyRepository $apiKeys;
    private AdminClientsAction $screen;

    protected function setUp(): void
    {
        $this->context = new AdminContext();
        $this->context->set(new AuthenticatedAdmin(1, 'Ada Admin', 'admin@gomrok.test', 'admin'));

        $this->clients = new InMemoryClientRepository();
        $this->clientDirectory = new InMemoryClientDirectory();
        $this->apiKeys = new InMemoryClientApiKeyRepository();

        $clientsScreenHandler = new ClientsScreenHandler(
            $this->clientDirectory,
            $this->apiKeys,
            new StubProviderAccountDirectory(),
        );

        $this->screen = new AdminClientsAction(
            $this->context,
            $clientsScreenHandler,
            new InMemoryReferenceCatalog(),
            RealAdminViewRenderer::create(),
        );
    }

    #[Test]
    public function aFailedCreateClientSubmissionReopensTheModalWithEverythingTyped(): void
    {
        $action = new AdminClientsCreateAction(
            $this->context,
            new ClientsScreenHandler($this->clientDirectory, $this->apiKeys, new StubProviderAccountDirectory()),
            new CreateClientHandler(
                $this->clients,
                $this->apiKeys,
                new class () implements ApiKeyGenerator {
                    public function generate(int $clientId, ApiKeyPrefix $prefix, ?string $label, DateTimeImmutable $now, ?DateTimeImmutable $expiresAt = null): GeneratedApiKey
                    {
                        throw new \LogicException('Should never be reached — the slug is invalid before this point.');
                    }
                },
                new FixedTokenGenerator(),
                new InMemoryReferenceCatalog(),
                new RecordingAuditLogWriter(),
                new SynchronousTransactions(),
                new FrozenClock('2026-09-24T12:00:00+00:00'),
            ),
            new InMemoryReferenceCatalog(),
            RealAdminViewRenderer::create(),
            $this->screen,
        );

        // "Invalid Slug!" fails ClientSlug::isValid() ("client.invalid_slug")
        // before anything else runs — deterministic, no DB needed.
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/clients')
            ->withParsedBody([
                'slug' => 'Invalid Slug!',
                'name' => 'Acme Inc',
                'default_currency' => 'EUR',
                'environment' => 'live',
                'default_country' => 'DE',
                'timezone' => 'Europe/Berlin',
            ]);

        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));

        $html = html_entity_decode((string) $response->getBody(), \ENT_QUOTES | \ENT_HTML5);

        self::assertStringContainsString('open("create-client"', $html);
        self::assertStringContainsString('"slug":"Invalid Slug!"', $html);
        self::assertStringContainsString('"name":"Acme Inc"', $html);
        self::assertStringContainsString('"default_currency":"EUR"', $html);
        self::assertStringContainsString('"environment":"live"', $html);
        self::assertStringContainsString('"timezone":"Europe\\/Berlin"', $html);
        self::assertStringContainsString('lowercase letters, digits or hyphens', $html);

        self::assertNull($this->clients->findBySlug('invalid-slug'));
        self::assertNull($this->clients->findBySlug('Invalid Slug!'));
    }

    #[Test]
    public function aFailedEditClientSubmissionReopensTheModalWithTheAttemptedEditNotTheOriginalRow(): void
    {
        $client = Client::register(
            ClientSlug::of('acme'),
            'Acme Inc',
            Currency::of('USD'),
            null,
            'UTC',
            'signing-secret',
            new DateTimeImmutable('2026-09-24T12:00:00+00:00'),
        );
        $this->clients->save($client);
        $clientId = $client->id();
        self::assertNotNull($clientId);
        $this->clientDirectory->add(new ClientSnapshot($clientId, 'acme', 'Acme Inc', ClientStatus::Active, 'USD', null, 'UTC'));

        $action = new AdminClientsUpdateAction(
            $this->context,
            new UpdateClientHandler($this->clients, new InMemoryReferenceCatalog(), new RecordingAuditLogWriter(), new SynchronousTransactions(), new FrozenClock('2026-09-24T12:00:00+00:00')),
            $this->screen,
        );

        // A blank name is rejected by UpdateClientHandler ("client.name_required").
        $request = (new ServerRequestFactory())->createServerRequest('POST', "/admin/clients/{$clientId}")
            ->withParsedBody(['name' => '', 'default_currency' => 'GBP', 'default_country' => 'GB', 'timezone' => 'Europe/London']);

        $response = $action($request, (new ResponseFactory())->createResponse(), ['clientId' => (string) $clientId]);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));

        $html = html_entity_decode((string) $response->getBody(), \ENT_QUOTES | \ENT_HTML5);

        self::assertStringContainsString('open("edit-client"', $html);
        // The attempted (invalid) edit is what's shown — GBP/London — not the
        // client's actual stored USD/UTC values.
        self::assertStringContainsString('"default_currency":"GBP"', $html);
        self::assertStringContainsString('"timezone":"Europe\\/London"', $html);
        self::assertStringContainsString('A client name is required.', $html);

        // The client itself was never mutated.
        $stored = $this->clients->findById($clientId);
        self::assertNotNull($stored);
        self::assertSame('Acme Inc', $stored->name());
        self::assertSame('USD', $stored->defaultCurrency()->code());
    }
}
