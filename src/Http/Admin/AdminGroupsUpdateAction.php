<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Pricing\Application\ChangePricingGroupStatus\ChangePricingGroupStatusHandler;
use Gomrok\Modules\Pricing\Application\SetPricingGroupCountries\SetPricingGroupCountriesCommand;
use Gomrok\Modules\Pricing\Application\SetPricingGroupCountries\SetPricingGroupCountriesHandler;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/packaging/groups/{groupId}` (Phase 27 Increment B) — the
 * "Edit pricing group" modal's submit target: countries (full replace) and
 * the active/disabled toggle. The default group's countries and status can't
 * be changed (same rules {@see SetPricingGroupCountriesHandler} and
 * {@see ChangePricingGroupStatusHandler} already enforce), so both edits are
 * simply skipped for it rather than surfaced as errors.
 */
final readonly class AdminGroupsUpdateAction
{
    use RedirectsToPackaging;

    public function __construct(
        private AdminContext $context,
        private PricingGroupRepository $groups,
        private SetPricingGroupCountriesHandler $setCountries,
        private ChangePricingGroupStatusHandler $changeStatus,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::PricingUpdate)) {
            return AdminPermissionGuard::deny($response);
        }

        $groupId = (int) $args['groupId'];
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            return $this->redirectToPackaging($response, ['tab' => 'groups'], error: 'Pricing group not found.');
        }

        $body = AdminForm::body($request);
        $active = AdminForm::checked($body, 'active');
        $slug = $group->slug()->value;

        if (!$group->isDefault()) {
            $countries = $this->splitCountries(AdminForm::str($body, 'countries'));
            $countriesResult = $this->setCountries->handle(new SetPricingGroupCountriesCommand(
                groupId: $groupId,
                countries: $countries,
                actorId: $this->context->admin()->id,
            ));
            if ($countriesResult->isErr()) {
                return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug], error: $countriesResult->error()->message);
            }

            $statusResult = $active
                ? $this->changeStatus->enable($groupId, $this->context->admin()->id)
                : $this->changeStatus->disable($groupId, $this->context->admin()->id);
            if ($statusResult->isErr()) {
                return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug], error: $statusResult->error()->message);
            }
        }

        return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug], success: 'Pricing group updated.');
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
