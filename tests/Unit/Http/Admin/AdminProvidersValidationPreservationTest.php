<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http\Admin;

use DateTimeImmutable;
use Gomrok\Http\Admin\AdminProviderAccountsCreateAction;
use Gomrok\Http\Admin\AdminProviderAccountsUpdateAction;
use Gomrok\Http\Admin\AdminProvidersAction;
use Gomrok\Modules\Admin\Application\Providers\AccountsTabHandler;
use Gomrok\Modules\Admin\Application\Providers\GroupsTabHandler;
use Gomrok\Modules\Admin\Application\Providers\UpdateProviderAccountForAdmin\UpdateProviderAccountForAdminCommand;
use Gomrok\Modules\Admin\Application\Providers\UpdateProviderAccountForAdmin\UpdateProviderAccountForAdminHandler;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Providers\Application\ChangeProviderAccountStatus\ChangeProviderAccountStatusHandler;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountHandler;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Application\SetProviderAccountMarkets\SetProviderAccountMarketsHandler;
use Gomrok\Modules\Providers\Domain\EncryptedSecret;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderAccount;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Modules\Providers\Domain\ProviderAccountSlug;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AuthenticatedAdmin;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryProviderAccountRepository;
use Gomrok\Tests\Support\InMemoryProviderGroupRepository;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Gomrok\Tests\Support\RealAdminViewRenderer;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderCatalog;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The validation-preserving forms rule (`.claude/docs/Ui.md`), exercised
 * end-to-end through the real `providers.html.twig` template — mirrors
 * `AdminPackagingValidationPreservationTest`.
 */
final class AdminProvidersValidationPreservationTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryClientDirectory $clients;
    private AdminContext $context;
    private AdminProvidersAction $screen;

    protected function setUp(): void
    {
        $this->clients = new InMemoryClientDirectory();
        $this->clients->add(new ClientSnapshot(self::CLIENT, 'televika', 'Televika', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $this->context = new AdminContext();
        $this->context->set(new AuthenticatedAdmin(1, 'Ada Admin', 'admin@gomrok.test', 'admin'));

        $groups = new InMemoryProviderGroupRepository();
        $accountsDirectory = new StubProviderAccountDirectory();

        $accountsTab = new AccountsTabHandler(
            $accountsDirectory,
            new StubProviderCatalog(),
            new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders()),
            $groups,
        );
        $groupsTab = new GroupsTabHandler($groups, $accountsDirectory);

        $this->screen = new AdminProvidersAction(
            $this->context,
            $this->clients,
            new StubProviderCatalog(),
            $accountsDirectory,
            $accountsTab,
            $groupsTab,
            new InMemoryReferenceCatalog(),
            RealAdminViewRenderer::create(),
        );
    }

    #[Test]
    public function aFailedCreateAccountSubmissionReopensTheModalWithEverythingTypedAndNoSecret(): void
    {
        $action = new AdminProviderAccountsCreateAction(
            $this->context,
            $this->clients,
            new CreateProviderAccountHandler(
                new InMemoryProviderAccountRepository(),
                $this->clients,
                new StubProviderCatalog(),
                new InMemoryReferenceCatalog(),
                new FakeSecretCipherForProvidersTest(),
                new RecordingAuditLogWriter(),
                new SynchronousTransactions(),
                new FrozenClock('2026-09-24T12:00:00+00:00'),
            ),
            $this->screen,
        );

        // "carbon" isn't 'live' or 'test' — CreateProviderAccountHandler
        // rejects it as "provider_account.invalid_mode", deterministically,
        // before ever touching the repository or the secret cipher.
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/providers/accounts')
            ->withParsedBody([
                'provider_type_code' => 'stripe',
                'mode' => 'carbon',
                'name' => 'My Stripe',
                'secret_key' => 'sk_super_secret_value',
                'public_key' => 'pk_visible',
                'slug' => 'my-stripe',
                'countries' => 'DE,AT',
                'methods' => ['card'],
            ]);

        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));

        $html = html_entity_decode((string) $response->getBody(), \ENT_QUOTES | \ENT_HTML5);

        self::assertStringContainsString('open("create-account"', $html);
        self::assertStringContainsString('"name":"My Stripe"', $html);
        self::assertStringContainsString('"slug":"my-stripe"', $html);
        self::assertStringContainsString('"countries":"DE, AT"', $html);
        self::assertStringContainsString('"mode":"carbon"', $html);
        self::assertStringContainsString("Mode must be 'live' or 'test'.", $html);

        // The typed secret never round-trips into the page.
        self::assertStringNotContainsString('sk_super_secret_value', $html);
        self::assertStringContainsString('"secret_key":""', $html);
    }

    #[Test]
    public function aFailedEditAccountSubmissionReopensTheModalWithTheAttemptedEditNotTheStoredAccount(): void
    {
        $accounts = new InMemoryProviderAccountRepository();
        $account = ProviderAccount::register(
            clientId: self::CLIENT,
            providerTypeId: 1,
            slug: ProviderAccountSlug::of('stripe-test'),
            name: 'Original Name',
            mode: ProviderAccountMode::Test,
            publicKey: 'pk_test_x',
            secret: new EncryptedSecret('ciphertext', 'abcd'),
            countryCodes: ['DE'],
            methods: [PaymentMethod::Card],
            now: new DateTimeImmutable('2026-09-24T12:00:00+00:00'),
        );
        $accounts->save($account);
        $accountId = $account->id();
        self::assertNotNull($accountId);

        $action = new AdminProviderAccountsUpdateAction(
            $this->context,
            new UpdateProviderAccountForAdminHandler(
                new SetProviderAccountMarketsHandler(
                    $accounts,
                    new InMemoryReferenceCatalog(),
                    new RecordingAuditLogWriter(),
                    new SynchronousTransactions(),
                    new FrozenClock('2026-09-24T12:00:00+00:00'),
                ),
                new ChangeProviderAccountStatusHandler($accounts, new RecordingAuditLogWriter(), new SynchronousTransactions(), new FrozenClock('2026-09-24T12:00:00+00:00')),
            ),
            $this->screen,
        );

        // "not-a-real-method" isn't a PaymentMethod case —
        // SetProviderAccountMarketsHandler rejects it as
        // "provider_account.unknown_method" before mutating anything.
        $request = (new ServerRequestFactory())->createServerRequest('POST', "/admin/providers/accounts/{$accountId}")
            ->withParsedBody([
                'slug' => 'stripe-test',
                'name' => 'Attempted New Name',
                'countries' => 'DE, FR',
                'methods' => ['not-a-real-method'],
                'active' => 'on',
            ]);

        $response = $action($request, (new ResponseFactory())->createResponse(), ['accountId' => (string) $accountId]);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));

        $html = html_entity_decode((string) $response->getBody(), \ENT_QUOTES | \ENT_HTML5);

        self::assertStringContainsString('open("edit-account"', $html);
        // The attempted edit is shown, not the account's real stored name.
        self::assertStringContainsString('"name":"Attempted New Name"', $html);
        self::assertStringContainsString("Unknown payment method 'not-a-real-method'.", $html);

        // Nothing was actually mutated.
        $stored = $accounts->findById($accountId);
        self::assertNotNull($stored);
        self::assertSame('Original Name', $stored->name());
    }
}
