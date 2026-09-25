<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Packaging\SetGroupPackagePriceForAdmin\SetGroupPackagePriceForAdminCommand;
use Gomrok\Modules\Admin\Application\Packaging\SetGroupPackagePriceForAdmin\SetGroupPackagePriceForAdminHandler;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\AdminPermissionGuard;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/packaging/groups/{groupId}/packages/{packageId}/price`
 * (Phase 27 Increment B) — the group-level "Edit price" modal: set the
 * package to `default` (use its base price), `override` (a group-specific
 * amount, always in the group's own currency), or `disabled` (hidden in this
 * group).
 */
final readonly class AdminGroupPackagePriceAction
{
    use RedirectsToPackaging;

    public function __construct(
        private AdminContext $context,
        private PricingGroupRepository $groups,
        private SetGroupPackagePriceForAdminHandler $handler,
        private PackageDirectory $packages,
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
        $packageId = (int) $args['packageId'];

        $group = $this->groups->findById($groupId);
        if ($group === null) {
            return $this->redirectToPackaging($response, ['tab' => 'groups'], error: 'Pricing group not found.');
        }
        $slug = $group->slug()->value;

        $body = AdminForm::body($request);
        $status = AdminForm::str($body, 'status', 'default');
        $amount = AdminForm::str($body, 'amount');
        $package = $this->packages->findById($packageId);
        $packageName = $package !== null ? $package->name : '';
        $submittedValues = ['group_id' => $groupId, 'group_slug' => $slug, 'package_id' => $packageId, 'package_name' => $packageName, 'status' => $status, 'amount' => $amount];

        $amountMinor = null;
        if ($status === 'override') {
            try {
                $currency = Currency::of($group->currencyCode());
            } catch (InvalidArgumentException) {
                return $this->screen->reopen($request, $response, ['tab' => 'groups', 'group' => $slug], new AdminModalReopen('group-price', $submittedValues, 'Invalid group currency.'));
            }
            $money = Money::fromDecimalInput($amount, $currency);
            if ($money === null) {
                return $this->screen->reopen($request, $response, ['tab' => 'groups', 'group' => $slug], new AdminModalReopen('group-price', $submittedValues, "'{$amount}' is not a valid price for {$currency->code()}."));
            }
            $amountMinor = $money->toMinor();
        }

        $result = $this->handler->handle(new SetGroupPackagePriceForAdminCommand(
            pricingGroupId: $groupId,
            packageId: $packageId,
            status: $status,
            amountMinor: $amountMinor,
            currency: $amountMinor !== null ? $group->currencyCode() : null,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->screen->reopen($request, $response, ['tab' => 'groups', 'group' => $slug], new AdminModalReopen('group-price', $submittedValues, $result->error()->message));
        }

        return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug], success: 'Price updated.');
    }
}
