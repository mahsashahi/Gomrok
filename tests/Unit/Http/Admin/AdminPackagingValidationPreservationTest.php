<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http\Admin;

use DateTimeImmutable;
use Gomrok\Http\Admin\AdminGroupsCreateAction;
use Gomrok\Http\Admin\AdminGroupsUpdateAction;
use Gomrok\Http\Admin\AdminPackagingAction;
use Gomrok\Modules\Admin\Application\Packaging\GroupsTabHandler;
use Gomrok\Modules\Admin\Application\Packaging\PackagesTabHandler;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Packages\Application\PackagePurchaseCapabilityResolver;
use Gomrok\Modules\Pricing\Application\ChangePricingGroupStatus\ChangePricingGroupStatusHandler;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupHandler;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
use Gomrok\Modules\Pricing\Application\ResolveVisitorPriceListAssignment;
use Gomrok\Modules\Pricing\Application\SetPricingGroupCountries\SetPricingGroupCountriesHandler;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AuthenticatedAdmin;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryPackageProviderDefinitionDirectory;
use Gomrok\Tests\Support\InMemoryPackageProviderDefinitionRepository;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListAssignmentRepository;
use Gomrok\Tests\Support\InMemoryPriceListDirectory;
use Gomrok\Tests\Support\InMemoryPriceListPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListRepository;
use Gomrok\Tests\Support\InMemoryPriceRuleRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RealAdminViewRenderer;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The validation-preserving forms rule (`.claude/docs/Ui.md`), exercised
 * end-to-end through the real `packaging.html.twig` template: a failed
 * create or edit submission must re-render the same screen (not redirect),
 * with the modal reopened and every submitted value still in place.
 */
final class AdminPackagingValidationPreservationTest extends TestCase
{
    private const CLIENT = 7;

    private DateTimeImmutable $now;
    private InMemoryClientDirectory $clients;
    private InMemoryPricingGroupRepository $groups;
    private AdminContext $context;
    private AdminPackagingAction $screen;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-24T12:00:00+00:00');
        $this->clients = new InMemoryClientDirectory();
        $this->clients->add(new ClientSnapshot(self::CLIENT, 'televika', 'Televika', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $this->context = new AdminContext();
        $this->context->set(new AuthenticatedAdmin(1, 'Ada Admin', 'admin@gomrok.test', 'admin'));

        $this->groups = new InMemoryPricingGroupRepository();

        $packages = new StubPackageDirectory();
        $defaults = new InMemoryDefaultPackagePriceRepository();
        $priceLists = new InMemoryPriceListRepository();
        $listPackages = new InMemoryPriceListPackageRepository();
        $rows = new InMemoryPricingGroupPackageRepository();
        $rates = new InMemoryClientExchangeRateRepository();
        $priceListResolver = new PriceListResolver($priceLists, $listPackages);
        $clock = new FrozenClock('2026-09-24T12:00:00+00:00');

        $priceResolver = new PriceResolver(
            $this->groups,
            $rows,
            $defaults,
            $rates,
            $packages,
            $priceListResolver,
            new PriceRuleResolver(new InMemoryPriceRuleRepository()),
            new ResolveVisitorPriceListAssignment(new InMemoryPriceListAssignmentRepository(), $priceLists, $clock),
            $clock,
        );

        $packagesTab = new PackagesTabHandler(
            $this->clients,
            $packages,
            new PackagePurchaseCapabilityResolver(new InMemoryPackageRepository()),
            new InMemoryPackageProviderDefinitionDirectory(new InMemoryPackageProviderDefinitionRepository()),
            new StubProviderAccountDirectory(),
            $this->groups,
            $priceResolver,
            $priceListResolver,
            $rates,
            $defaults,
            $clock,
        );

        $groupsTab = new GroupsTabHandler(
            $this->clients,
            $packages,
            new PackagePurchaseCapabilityResolver(new InMemoryPackageRepository()),
            $this->groups,
            new InMemoryPriceListDirectory($priceLists, $listPackages),
            $priceResolver,
            $priceListResolver,
            new StubProviderAccountDirectory(),
            $rates,
            $defaults,
            $rows,
            $clock,
        );

        $this->screen = new AdminPackagingAction(
            $this->context,
            $this->clients,
            new StubProviderAccountDirectory(),
            $packagesTab,
            $groupsTab,
            new InMemoryReferenceCatalog(),
            RealAdminViewRenderer::create(),
        );
    }

    #[Test]
    public function aFailedCreateGroupSubmissionReopensTheModalWithEverythingTyped(): void
    {
        $action = new AdminGroupsCreateAction(
            $this->context,
            $this->clients,
            new CreatePricingGroupHandler(
                $this->groups,
                new InMemoryPriceListRepository(),
                $this->clients,
                new InMemoryReferenceCatalog(),
                new RecordingAuditLogWriter(),
                new SynchronousTransactions(),
                new FrozenClock('2026-09-24T12:00:00+00:00'),
            ),
            new SetPricingGroupCountriesHandler(
                $this->groups,
                new InMemoryReferenceCatalog(),
                new RecordingAuditLogWriter(),
                new SynchronousTransactions(),
                new FrozenClock('2026-09-24T12:00:00+00:00'),
            ),
            $this->screen,
        );

        // A blank name is rejected by CreatePricingGroupHandler
        // ("pricing_group.name_required") — deterministic, no DB needed.
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/packaging/groups')
            ->withParsedBody([
                'name' => '',
                'currency' => 'EUR',
                'slug' => 'my-new-group',
                'device_type' => 'web',
                'priority' => '42',
                'countries' => 'DE,AT',
            ]);

        $response = $action($request, (new ResponseFactory())->createResponse());

        // Never a redirect — the screen itself re-renders.
        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));

        // Decoded the same way a browser decodes an HTML attribute before
        // Alpine evaluates it — the raw markup numeric-escapes almost every
        // non-alphanumeric character (Twig's `html_attr` strategy), which
        // would make a literal-character assertion here fragile and
        // Twig-version-specific rather than a check on what the browser
        // actually runs.
        $html = html_entity_decode((string) $response->getBody(), \ENT_QUOTES | \ENT_HTML5);

        // The modal reopens itself on load, carrying the submitted values —
        // not the empty defaults the "+ New pricing group" button would use.
        self::assertStringContainsString('open("create-group"', $html);
        self::assertStringContainsString('"slug":"my-new-group"', $html);
        self::assertStringContainsString('"currency":"EUR"', $html);
        self::assertStringContainsString('"priority":42', $html);
        self::assertStringContainsString('"countries":"DE,AT"', $html);
        self::assertStringContainsString('A name is required.', $html);

        // Nothing was actually created.
        self::assertSame([], $this->groups->forClient(self::CLIENT));
    }

    #[Test]
    public function aFailedEditGroupSubmissionReopensTheModalWithTheAttemptedEditNotTheOriginalRow(): void
    {
        $this->groups->save(PricingGroup::define(self::CLIENT, PricingGroupSlug::of('dach'), 'DACH', 1, null, 'EUR', false, $this->now));
        $groupId = $this->groups->forClient(self::CLIENT)[0]->id();
        self::assertNotNull($groupId);

        $action = new AdminGroupsUpdateAction(
            $this->context,
            $this->groups,
            new SetPricingGroupCountriesHandler(
                $this->groups,
                new InMemoryReferenceCatalog(),
                new RecordingAuditLogWriter(),
                new SynchronousTransactions(),
                new FrozenClock('2026-09-24T12:00:00+00:00'),
            ),
            new ChangePricingGroupStatusHandler($this->groups, new RecordingAuditLogWriter(), new SynchronousTransactions(), new FrozenClock('2026-09-24T12:00:00+00:00')),
            $this->screen,
        );

        // "ZZ" isn't a configured market (InMemoryReferenceCatalog's default
        // list) — SetPricingGroupCountriesHandler rejects it as
        // "pricing_group.unknown_country". The group's real countries stay
        // AT/CH/DE (from its original definition) — none of this attempted
        // edit is persisted.
        $request = (new ServerRequestFactory())->createServerRequest('POST', "/admin/packaging/groups/{$groupId}")
            ->withParsedBody(['countries' => 'DE, ZZ', 'active' => 'on']);

        $response = $action($request, (new ResponseFactory())->createResponse(), ['groupId' => (string) $groupId]);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));

        $html = html_entity_decode((string) $response->getBody(), \ENT_QUOTES | \ENT_HTML5);

        self::assertStringContainsString('open("edit-group"', $html);
        // The attempted (invalid) edit is what's shown, not what's on the
        // group row today (DACH currently has no countries set).
        self::assertStringContainsString('"countries":"DE, ZZ"', $html);
        self::assertStringContainsString('is not a configured market', $html);

        // The group itself was never mutated.
        self::assertSame([], $this->groups->findById($groupId)?->countryCodes() ?? ['unexpectedly missing']);
    }
}
