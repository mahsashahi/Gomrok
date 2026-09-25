<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Pricing\Application\ChangePricingGroupStatus\ChangePricingGroupStatusHandler;
use Gomrok\Modules\Pricing\Application\SetPricingGroupCountries\SetPricingGroupCountriesCommand;
use Gomrok\Modules\Pricing\Application\SetPricingGroupCountries\SetPricingGroupCountriesHandler;
use Gomrok\Modules\Pricing\Application\UpdatePricingGroup\UpdatePricingGroupCommand;
use Gomrok\Modules\Pricing\Application\UpdatePricingGroup\UpdatePricingGroupHandler;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/packaging/groups/{groupId}` (Phase 27 Increment B; field-parity
 * with Create added 2026-09-24) — the "Edit pricing group" modal's submit
 * target, composing every mutable {@see \Gomrok\Modules\Pricing\Domain\PricingGroup}
 * field: name/slug/priority/deviceType ({@see UpdatePricingGroupHandler}),
 * countries (full replace, {@see SetPricingGroupCountriesHandler}), and the
 * active/disabled toggle ({@see ChangePricingGroupStatusHandler}). `currency`
 * and `isDefault` are never submitted for a change — intentionally immutable
 * post-creation (see the {@see \Gomrok\Modules\Pricing\Domain\PricingGroup}
 * class docblock). The default group's priority/countries/status can't be
 * changed (the handlers themselves enforce this), so those three are simply
 * skipped for it rather than surfaced as errors — name/slug/deviceType still
 * apply.
 */
final readonly class AdminGroupsUpdateAction
{
    use RedirectsToPackaging;

    public function __construct(
        private AdminContext $context,
        private PricingGroupRepository $groups,
        private UpdatePricingGroupHandler $updateFields,
        private SetPricingGroupCountriesHandler $setCountries,
        private ChangePricingGroupStatusHandler $changeStatus,
        private AdminPackagingAction $screen,
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
        $name = AdminForm::str($body, 'name');
        $slug = AdminForm::nullableStr($body, 'slug');
        $priority = AdminForm::nullableInt($body, 'priority') ?? $group->priority();
        $deviceType = AdminForm::nullableStr($body, 'device_type');
        $active = AdminForm::checked($body, 'active');
        $rawCountries = AdminForm::str($body, 'countries');

        $currentSlug = $group->slug()->value;
        $submittedValues = [
            'id' => $groupId,
            'name' => $name,
            'slug' => $slug ?? $currentSlug,
            'priority' => $priority,
            'device_type' => $deviceType ?? '',
            'countries' => $rawCountries,
            'active' => $active,
            'is_default' => $group->isDefault(),
            'currency' => $group->currencyCode(),
        ];

        $fieldsResult = $this->updateFields->handle(new UpdatePricingGroupCommand(
            groupId: $groupId,
            name: $name,
            slug: $slug,
            priority: $priority,
            deviceType: $deviceType,
            actorId: $this->context->admin()->id,
        ));
        if ($fieldsResult->isErr()) {
            return $this->screen->reopen($request, $response, ['tab' => 'groups', 'group' => $currentSlug], new AdminModalReopen('edit-group', $submittedValues, $fieldsResult->error()->message));
        }

        // Rename/rescope may have just changed the slug — reload for the
        // country/status steps below and for the final redirect.
        $group = $this->groups->findById($groupId);
        \assert($group !== null);
        $slug = $group->slug()->value;
        $submittedValues['slug'] = $slug;

        if (!$group->isDefault()) {
            $countries = $this->splitCountries($rawCountries);

            $countriesResult = $this->setCountries->handle(new SetPricingGroupCountriesCommand(
                groupId: $groupId,
                countries: $countries,
                actorId: $this->context->admin()->id,
            ));
            if ($countriesResult->isErr()) {
                return $this->screen->reopen($request, $response, ['tab' => 'groups', 'group' => $slug], new AdminModalReopen('edit-group', $submittedValues, $countriesResult->error()->message));
            }

            $statusResult = $active
                ? $this->changeStatus->enable($groupId, $this->context->admin()->id)
                : $this->changeStatus->disable($groupId, $this->context->admin()->id);
            if ($statusResult->isErr()) {
                return $this->screen->reopen($request, $response, ['tab' => 'groups', 'group' => $slug], new AdminModalReopen('edit-group', $submittedValues, $statusResult->error()->message));
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
