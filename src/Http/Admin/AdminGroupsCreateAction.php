<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupCommand;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupHandler;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupResult;
use Gomrok\Modules\Pricing\Application\SetPricingGroupCountries\SetPricingGroupCountriesCommand;
use Gomrok\Modules\Pricing\Application\SetPricingGroupCountries\SetPricingGroupCountriesHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/packaging/groups` (Phase 27 Increment B) — the "Create
 * pricing group" modal's submit target. Countries are a comma/newline
 * separated free-text field, split and forwarded to
 * {@see SetPricingGroupCountriesHandler} as a second step (the create
 * handler itself only takes name/currency/priority — countries are always a
 * full replace, same shape as the edit path).
 */
final readonly class AdminGroupsCreateAction
{
    use RedirectsToPackaging;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private CreatePricingGroupHandler $createGroup,
        private SetPricingGroupCountriesHandler $setCountries,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::PricingCreate)) {
            return AdminPermissionGuard::deny($response);
        }

        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        if ($activeClient === null) {
            return $this->redirectToPackaging($response, ['tab' => 'groups'], error: 'No active client.');
        }

        $body = AdminForm::body($request);
        $name = AdminForm::str($body, 'name');
        $currency = AdminForm::str($body, 'currency', $activeClient->defaultCurrency);
        $isDefault = AdminForm::checked($body, 'is_default');
        $slug = AdminForm::nullableStr($body, 'slug');
        $deviceType = AdminForm::nullableStr($body, 'device_type');
        $priority = AdminForm::nullableInt($body, 'priority') ?? 100;
        $countries = $isDefault ? [] : $this->splitCountries(AdminForm::str($body, 'countries'));

        $created = $this->createGroup->handle(new CreatePricingGroupCommand(
            clientId: $activeClient->id,
            name: $name,
            currency: $currency,
            isDefault: $isDefault,
            slug: $slug,
            deviceType: $deviceType,
            priority: $priority,
            actorId: $this->context->admin()->id,
        ));

        if ($created->isErr()) {
            return $this->redirectToPackaging($response, ['tab' => 'groups'], error: $created->error()->message);
        }

        $result = $created->value();
        \assert($result instanceof CreatePricingGroupResult);

        if ($countries !== []) {
            $countriesResult = $this->setCountries->handle(new SetPricingGroupCountriesCommand(
                groupId: $result->groupId,
                countries: $countries,
                actorId: $this->context->admin()->id,
            ));
            if ($countriesResult->isErr()) {
                return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $result->slug], error: $countriesResult->error()->message);
            }
        }

        return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $result->slug], success: 'Pricing group created.');
    }

    /**
     * @return list<string>
     */
    private function splitCountries(string $raw): array
    {
        $parts = preg_split('/[,\s]+/', $raw);
        $parts = $parts !== false ? $parts : [];

        return array_values(array_filter(array_map(static fn (string $c): string => trim($c), $parts), static fn (string $c): bool => $c !== ''));
    }
}
