<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Providers\UpdateProviderGroupForAdmin\UpdateProviderGroupForAdminCommand;
use Gomrok\Modules\Admin\Application\Providers\UpdateProviderGroupForAdmin\UpdateProviderGroupForAdminHandler;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/providers/groups/{groupId}` (Phase 27) — the "Edit routing
 * group" modal's submit target: name, countries, purchase types, methods,
 * currency, active status.
 */
final readonly class AdminProviderGroupsUpdateAction
{
    use RedirectsToProviders;

    public function __construct(
        private AdminContext $context,
        private ProviderGroupRepository $groups,
        private UpdateProviderGroupForAdminHandler $handler,
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

        $groupId = (int) $args['groupId'];
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            return $this->redirectToProviders($response, ['tab' => 'groups'], error: 'Routing group not found.');
        }
        $slug = $group->slug()->value;

        $body = AdminForm::body($request);
        $name = AdminForm::str($body, 'name');
        $countries = self::splitList(AdminForm::str($body, 'countries'));
        $purchaseTypes = AdminForm::strArray($body, 'purchase_types');
        $methods = AdminForm::strArray($body, 'methods');
        $currencyCode = AdminForm::nullableStr($body, 'currency_code');
        $active = AdminForm::checked($body, 'active');

        $result = $this->handler->handle(new UpdateProviderGroupForAdminCommand(
            groupId: $groupId,
            name: $name,
            countries: $countries,
            purchaseTypes: $purchaseTypes,
            methods: $methods,
            currencyCode: $currencyCode,
            active: $active,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], error: $result->error()->message);
        }

        return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], success: 'Routing group updated.');
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
