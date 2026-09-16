<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Providers\UpdateProviderAccountForAdmin\UpdateProviderAccountForAdminCommand;
use Gomrok\Modules\Admin\Application\Providers\UpdateProviderAccountForAdmin\UpdateProviderAccountForAdminHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/providers/accounts/{accountId}` (Phase 27) — the "Edit
 * account" modal's submit target: name, markets, active status. Secret
 * rotation is a separate action.
 */
final readonly class AdminProviderAccountsUpdateAction
{
    use RedirectsToProviders;

    public function __construct(
        private AdminContext $context,
        private UpdateProviderAccountForAdminHandler $handler,
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
        $name = AdminForm::str($body, 'name');
        $countries = self::splitList(AdminForm::str($body, 'countries'));
        $methods = AdminForm::strArray($body, 'methods');
        $active = AdminForm::checked($body, 'active');

        $result = $this->handler->handle(new UpdateProviderAccountForAdminCommand(
            accountId: $accountId,
            name: $name,
            countries: $countries,
            methods: $methods,
            active: $active,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToProviders($response, ['tab' => 'accounts', 'account' => $slug], error: $result->error()->message);
        }

        return $this->redirectToProviders($response, ['tab' => 'accounts', 'account' => $slug], success: 'Account updated.');
    }

    /**
     * @return list<string>
     */
    private static function splitList(string $raw): array
    {
        $parts = preg_split('/[,\s]+/', $raw);
        $parts = $parts !== false ? $parts : [];

        return array_values(array_filter(array_map(static fn (string $c): string => trim($c), $parts), static fn (string $c): bool => $c !== ''));
    }
}
