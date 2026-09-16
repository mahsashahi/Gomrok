<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Providers\Application\RotateProviderAccountSecret\RotateProviderAccountSecretCommand;
use Gomrok\Modules\Providers\Application\RotateProviderAccountSecret\RotateProviderAccountSecretHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/providers/accounts/{accountId}/rotate-secret` (Phase 27) — a
 * separate, more sensitive action than the edit modal (CLAUDE.md: "sensitive
 * actions must be logged, including ... secret rotation"); always audited
 * with the acting admin's id since {@see RotateProviderAccountSecretCommand}
 * takes one, unlike account creation.
 */
final readonly class AdminProviderAccountRotateSecretAction
{
    use RedirectsToProviders;

    public function __construct(
        private AdminContext $context,
        private RotateProviderAccountSecretHandler $handler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ProviderConfigsUpdate)) {
            return AdminPermissionGuard::deny($response);
        }

        $accountId = (int) $args['accountId'];
        $body = AdminForm::body($request);
        $slug = AdminForm::nullableStr($body, 'slug');
        $newSecretKey = AdminForm::str($body, 'new_secret_key');
        $newPublicKey = AdminForm::nullableStr($body, 'new_public_key');
        $replacePublicKey = AdminForm::checked($body, 'replace_public_key');

        $result = $this->handler->handle(new RotateProviderAccountSecretCommand(
            accountId: $accountId,
            newSecretKey: $newSecretKey,
            newPublicKey: $newPublicKey,
            replacePublicKey: $replacePublicKey,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToProviders($response, ['tab' => 'accounts', 'account' => $slug], error: $result->error()->message);
        }

        return $this->redirectToProviders($response, ['tab' => 'accounts', 'account' => $slug], success: 'Secret rotated.');
    }
}
