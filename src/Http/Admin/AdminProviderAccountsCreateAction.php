<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountCommand;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountHandler;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountResult;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/providers/accounts` (Phase 27) — the "Connect provider
 * account" modal's submit target. Scoped to the admin panel's active client.
 * `CreateProviderAccountHandler` always audits as `forSystem` (no `actorId`
 * parameter on its command) — a known gap in the underlying handler, not
 * something this action can attribute to the admin who clicked the button.
 */
final readonly class AdminProviderAccountsCreateAction
{
    use RedirectsToProviders;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private CreateProviderAccountHandler $handler,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ProviderConfigsCreate)) {
            return AdminPermissionGuard::deny($response);
        }

        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        if ($activeClient === null) {
            return $this->redirectToProviders($response, ['tab' => 'accounts'], error: 'No active client.');
        }

        $body = AdminForm::body($request);
        $providerTypeCode = AdminForm::str($body, 'provider_type_code');
        $mode = AdminForm::str($body, 'mode', 'test');
        $name = AdminForm::str($body, 'name');
        $secretKey = AdminForm::str($body, 'secret_key');
        $publicKey = AdminForm::nullableStr($body, 'public_key');
        $slug = AdminForm::nullableStr($body, 'slug');
        $countries = self::splitList(AdminForm::str($body, 'countries'));
        $methods = AdminForm::strArray($body, 'methods');

        $result = $this->handler->handle(new CreateProviderAccountCommand(
            clientId: $activeClient->id,
            providerTypeCode: $providerTypeCode,
            mode: $mode,
            name: $name,
            secretKey: $secretKey,
            publicKey: $publicKey,
            slug: $slug,
            countries: $countries,
            methods: $methods,
        ));

        if ($result->isErr()) {
            return $this->redirectToProviders($response, ['tab' => 'accounts'], error: $result->error()->message);
        }

        $value = $result->value();
        \assert($value instanceof CreateProviderAccountResult);

        return $this->redirectToProviders($response, ['tab' => 'accounts', 'account' => $value->slug], success: 'Provider account connected.');
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
