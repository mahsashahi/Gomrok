<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Packages\Application\LinkPackageProvider\LinkPackageProviderCommand;
use Gomrok\Modules\Packages\Application\LinkPackageProvider\LinkPackageProviderHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/packaging/packages/{packageId}/provider` (Phase 27 Increment
 * B) — manual provider registration only. {@see LinkPackageProviderHandler}'s
 * own docblock: "The provider-API product creation is deferred to the
 * adapter phases" — there is no "create via API" capability anywhere in the
 * codebase yet, so this screen only accepts a manually entered `remoteId`,
 * same as the handler itself supports. That gap is documented, not silently
 * routed around.
 */
final readonly class AdminPackageProviderLinkAction
{
    use RedirectsToPackaging;

    public function __construct(
        private AdminContext $context,
        private LinkPackageProviderHandler $handler,
        private AdminPackagingAction $screen,
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

        $packageId = (int) $args['packageId'];
        $body = AdminForm::body($request);
        $code = AdminForm::nullableStr($body, 'code');
        $providerAccountId = AdminForm::nullableInt($body, 'provider_account_id');
        $providerSideName = AdminForm::nullableStr($body, 'provider_side_name');
        $remoteId = AdminForm::nullableStr($body, 'remote_id');
        $submittedValues = ['provider_account_id' => $providerAccountId, 'provider_side_name' => $providerSideName ?? '', 'remote_id' => $remoteId ?? ''];

        if ($providerAccountId === null) {
            return $this->screen->reopen($request, $response, ['tab' => 'packages', 'package' => $code], new AdminModalReopen('link-provider', $submittedValues, 'Choose a provider account.'));
        }

        $result = $this->handler->handle(new LinkPackageProviderCommand(
            packageId: $packageId,
            providerAccountId: $providerAccountId,
            providerSideName: $providerSideName,
            remoteId: $remoteId,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->screen->reopen($request, $response, ['tab' => 'packages', 'package' => $code], new AdminModalReopen('link-provider', $submittedValues, $result->error()->message));
        }

        return $this->redirectToPackaging($response, ['tab' => 'packages', 'package' => $code], success: 'Provider linked.');
    }
}
