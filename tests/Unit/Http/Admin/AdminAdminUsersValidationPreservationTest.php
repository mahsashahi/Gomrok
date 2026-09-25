<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http\Admin;

use DateTimeImmutable;
use Gomrok\Http\Admin\AdminAdminUserPasswordResetAction;
use Gomrok\Http\Admin\AdminAdminUsersAction;
use Gomrok\Http\Admin\AdminAdminUsersCreateAction;
use Gomrok\Modules\Admin\Application\AdminUsers\AdminUsersScreenHandler;
use Gomrok\Modules\Admin\Application\CreateAdminUser\CreateAdminUserHandler;
use Gomrok\Modules\Admin\Application\ResetAdminUserPassword\ResetAdminUserPasswordHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AuthenticatedAdmin;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryAdminUserRepository;
use Gomrok\Tests\Support\RealAdminViewRenderer;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The validation-preserving forms rule (`.claude/docs/Ui.md`), exercised
 * end-to-end through the real `admin-users.html.twig` template: a failed
 * create or password-reset submission must re-render the same screen (not
 * redirect), with the modal reopened, every non-secret submitted value still
 * in place, and the typed password never echoed back into the page.
 */
final class AdminAdminUsersValidationPreservationTest extends TestCase
{
    private AdminContext $context;
    private InMemoryAdminUserRepository $users;
    private AdminAdminUsersAction $screen;

    protected function setUp(): void
    {
        $this->context = new AdminContext();
        $this->context->set(new AuthenticatedAdmin(1, 'Ada Admin', 'admin@gomrok.test', 'admin'));

        $this->users = new InMemoryAdminUserRepository();

        $this->screen = new AdminAdminUsersAction(
            $this->context,
            new AdminUsersScreenHandler($this->users),
            RealAdminViewRenderer::create(),
        );
    }

    #[Test]
    public function aFailedCreateSubmissionReopensTheModalWithoutEchoingThePassword(): void
    {
        $action = new AdminAdminUsersCreateAction(
            $this->context,
            new CreateAdminUserHandler(
                $this->users,
                new RecordingAuditLogWriter(),
                new SynchronousTransactions(),
                new FrozenClock('2026-09-24T12:00:00+00:00'),
            ),
            $this->screen,
        );

        // "too-short" is under CreateAdminUserHandler's 10-character minimum
        // ("admin_user.password_too_short") — deterministic, no DB needed.
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/admin-users')
            ->withParsedBody([
                'name' => 'New Support Agent',
                'email' => 'agent@gomrok.test',
                'password' => 'too-short',
                'role' => 'support_agent',
            ]);

        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));

        $rawHtml = (string) $response->getBody();
        // The typed password must not appear anywhere in the response body,
        // escaped or not — checked against the raw body, before any decoding.
        self::assertStringNotContainsString('too-short', $rawHtml);

        $html = html_entity_decode($rawHtml, \ENT_QUOTES | \ENT_HTML5);

        self::assertStringContainsString('open("create-user"', $html);
        self::assertStringContainsString('"name":"New Support Agent"', $html);
        self::assertStringContainsString('"email":"agent@gomrok.test"', $html);
        self::assertStringContainsString('"role":"support_agent"', $html);
        self::assertStringContainsString('"password":""', $html);
        self::assertStringContainsString('A password must be at least 10 characters.', $html);

        // Nothing was actually created.
        self::assertSame([], $this->users->all());
    }

    #[Test]
    public function aFailedPasswordResetReopensTheModalWithoutEchoingTheNewPassword(): void
    {
        $user = AdminUser::create('Existing Agent', 'existing@gomrok.test', 'irrelevant-hash', AdminRole::SupportAgent, new DateTimeImmutable('2026-09-01T00:00:00+00:00'));
        $this->users->save($user);
        $userId = $user->id();
        self::assertNotNull($userId);

        $action = new AdminAdminUserPasswordResetAction(
            $this->context,
            new ResetAdminUserPasswordHandler(
                $this->users,
                new RecordingAuditLogWriter(),
                new SynchronousTransactions(),
                new FrozenClock('2026-09-24T12:00:00+00:00'),
            ),
            $this->users,
            $this->screen,
        );

        $request = (new ServerRequestFactory())->createServerRequest('POST', "/admin/admin-users/{$userId}/reset-password")
            ->withParsedBody(['new_password' => 'short1']);

        $response = $action($request, (new ResponseFactory())->createResponse(), ['adminUserId' => (string) $userId]);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));

        $rawHtml = (string) $response->getBody();
        self::assertStringNotContainsString('short1', $rawHtml);

        $html = html_entity_decode($rawHtml, \ENT_QUOTES | \ENT_HTML5);

        self::assertStringContainsString('open("reset-password"', $html);
        self::assertStringContainsString('"name":"Existing Agent"', $html);
        self::assertStringContainsString('"new_password":""', $html);
        self::assertStringContainsString('A password must be at least 10 characters.', $html);
    }
}
