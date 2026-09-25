<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http\Admin;

use DateTimeImmutable;
use Gomrok\Http\Admin\AdminVouchersAction;
use Gomrok\Http\Admin\AdminVouchersCreateAction;
use Gomrok\Http\Admin\AdminVouchersUpdateAction;
use Gomrok\Modules\Admin\Application\Vouchers\UpdateVoucherForAdmin\UpdateVoucherForAdminHandler;
use Gomrok\Modules\Admin\Application\Vouchers\VouchersScreenHandler;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Vouchers\Application\ChangeVoucherStatus\ChangeVoucherStatusHandler;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherHandler;
use Gomrok\Modules\Vouchers\Application\UpdateVoucher\UpdateVoucherHandler;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AuthenticatedAdmin;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\InMemoryVoucherRepository;
use Gomrok\Tests\Support\RealAdminViewRenderer;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubVoucherDirectory;
use Gomrok\Tests\Support\StubVoucherRedemptionDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The validation-preserving forms rule (`.claude/docs/Ui.md`), exercised
 * end-to-end through the real `vouchers.html.twig` template: a failed
 * create or edit submission must re-render the same screen (not redirect),
 * with the modal reopened and every submitted value still in place.
 */
final class AdminVouchersValidationPreservationTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryClientDirectory $clients;
    private InMemoryVoucherRepository $vouchers;
    private AdminContext $context;
    private AdminVouchersAction $screen;

    protected function setUp(): void
    {
        $this->clients = new InMemoryClientDirectory();
        $this->clients->add(new ClientSnapshot(self::CLIENT, 'televika', 'Televika', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $this->context = new AdminContext();
        $this->context->set(new AuthenticatedAdmin(1, 'Ada Admin', 'admin@gomrok.test', 'admin'));

        $this->vouchers = new InMemoryVoucherRepository();

        $this->screen = new AdminVouchersAction(
            $this->context,
            $this->clients,
            new VouchersScreenHandler(new StubVoucherDirectory(), new StubVoucherRedemptionDirectory()),
            new InMemoryReferenceCatalog(),
            RealAdminViewRenderer::create(),
        );
    }

    #[Test]
    public function aFailedCreateVoucherSubmissionReopensTheModalWithEverythingTyped(): void
    {
        $action = new AdminVouchersCreateAction(
            $this->context,
            $this->clients,
            new CreateVoucherHandler(
                $this->vouchers,
                $this->clients,
                new InMemoryReferenceCatalog(),
                new RecordingAuditLogWriter(),
                new SynchronousTransactions(),
                new FrozenClock('2026-09-24T12:00:00+00:00'),
            ),
            $this->screen,
        );

        // "AB" is too short to match CreateVoucherHandler's code pattern
        // (3-64 chars) — "voucher.invalid_code", deterministic, no DB state
        // needed.
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/vouchers')
            ->withParsedBody([
                'code' => 'AB',
                'name' => 'Welcome Discount',
                'description' => 'For new signups',
                'valid_from' => '2026-01-01',
                'valid_until' => '2026-12-31',
                'first_purchase_only' => 'on',
                'min_purchase_amount' => '20.00',
                'min_purchase_currency' => 'EUR',
                'default_discount_type' => 'percentage',
                'default_percent' => '15',
            ]);

        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));

        // Decoded the same way a browser decodes an HTML attribute before
        // Alpine evaluates it — the raw markup numeric-escapes almost every
        // non-alphanumeric character (Twig's `html_attr` strategy).
        $html = html_entity_decode((string) $response->getBody(), \ENT_QUOTES | \ENT_HTML5);

        self::assertStringContainsString('open("create-voucher"', $html);
        self::assertStringContainsString('"code":"AB"', $html);
        self::assertStringContainsString('"name":"Welcome Discount"', $html);
        self::assertStringContainsString('"description":"For new signups"', $html);
        self::assertStringContainsString('"valid_from":"2026-01-01"', $html);
        self::assertStringContainsString('"first_purchase_only":true', $html);
        self::assertStringContainsString('"min_purchase_amount":"20.00"', $html);
        self::assertStringContainsString('"default_discount_type":"percentage"', $html);
        self::assertStringContainsString('"default_percent":"15"', $html);
        self::assertStringContainsString('must be 3-64 characters', $html);

        self::assertSame([], $this->vouchers->forClient(self::CLIENT));
    }

    #[Test]
    public function aFailedEditVoucherSubmissionReopensTheModalWithTheAttemptedEditNotTheOriginalRow(): void
    {
        $voucher = Voucher::create(
            self::CLIENT,
            'WELCOME10',
            'Welcome 10%',
            'Original description',
            null,
            null,
            false,
            null,
            null,
            DefaultDiscountType::Percentage,
            1000,
            new DateTimeImmutable('2026-09-24T12:00:00+00:00'),
        );
        $this->vouchers->save($voucher);
        $voucherId = $voucher->id();
        self::assertNotNull($voucherId);

        $action = new AdminVouchersUpdateAction(
            $this->context,
            $this->clients,
            new UpdateVoucherForAdminHandler(
                new UpdateVoucherHandler(
                    $this->vouchers,
                    new InMemoryReferenceCatalog(),
                    new RecordingAuditLogWriter(),
                    new SynchronousTransactions(),
                    new FrozenClock('2026-09-24T12:00:00+00:00'),
                ),
                new ChangeVoucherStatusHandler($this->vouchers, new RecordingAuditLogWriter(), new SynchronousTransactions(), new FrozenClock('2026-09-24T12:00:00+00:00')),
            ),
            $this->screen,
        );

        // A blank name is rejected by UpdateVoucherHandler
        // ("voucher.name_required") — the attempted edit (blank name, a new
        // description) must be what's shown, not the voucher's original
        // stored name/description.
        $request = (new ServerRequestFactory())->createServerRequest('POST', "/admin/vouchers/{$voucherId}")
            ->withParsedBody([
                'code' => 'WELCOME10',
                'name' => '',
                'description' => 'Attempted new description',
                'default_discount_type' => 'percentage',
                'default_percent' => '20',
                'active' => 'on',
            ]);

        $response = $action($request, (new ResponseFactory())->createResponse(), ['voucherId' => (string) $voucherId]);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));

        $html = html_entity_decode((string) $response->getBody(), \ENT_QUOTES | \ENT_HTML5);

        self::assertStringContainsString('open("edit-voucher"', $html);
        self::assertStringContainsString('"description":"Attempted new description"', $html);
        self::assertStringContainsString('"default_percent":"20"', $html);
        self::assertStringContainsString('A name is required.', $html);

        // The stored voucher itself was never mutated.
        $stored = $this->vouchers->findById($voucherId);
        self::assertNotNull($stored);
        self::assertSame('Welcome 10%', $stored->name());
        self::assertSame('Original description', $stored->description());
    }
}
