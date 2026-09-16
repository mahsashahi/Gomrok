<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\SetPriceListPackagePrice\SetPriceListPackagePriceCommand;
use Gomrok\Modules\Pricing\Application\SetPriceListPackagePrice\SetPriceListPackagePriceHandler;
use Gomrok\Modules\Pricing\Domain\PriceListRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/packaging/price-lists/{priceListId}/packages/{packageId}/price`
 * (Phase 27 Increment B) — pins an exact price for one package on one
 * non-control A/B list (always in the list's pricing-group currency).
 */
final readonly class AdminPriceListPackagePriceAction
{
    use RedirectsToPackaging;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private PriceListRepository $priceLists,
        private PricingGroupRepository $groups,
        private SetPriceListPackagePriceHandler $handler,
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

        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        if ($activeClient === null) {
            return $this->redirectToPackaging($response, ['tab' => 'groups'], error: 'No active client.');
        }

        $priceListId = (int) $args['priceListId'];
        $packageId = (int) $args['packageId'];

        $list = $this->priceLists->findById($priceListId);
        if ($list === null) {
            return $this->redirectToPackaging($response, ['tab' => 'groups'], error: 'Price list not found.');
        }
        $group = $this->groups->findById($list->pricingGroupId());
        $slug = $group?->slug()->value;
        $groupCurrency = $group !== null ? $group->currencyCode() : $activeClient->defaultCurrency;

        $body = AdminForm::body($request);
        $amount = AdminForm::str($body, 'amount');

        try {
            $currency = Currency::of($groupCurrency);
        } catch (InvalidArgumentException) {
            return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug, 'list' => $priceListId], error: 'Invalid group currency.');
        }
        $money = Money::fromDecimalInput($amount, $currency);
        if ($money === null) {
            return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug, 'list' => $priceListId], error: "'{$amount}' is not a valid price for {$currency->code()}.");
        }

        $result = $this->handler->handle(new SetPriceListPackagePriceCommand(
            clientId: $activeClient->id,
            priceListId: $priceListId,
            packageId: $packageId,
            amountMinor: $money->toMinor(),
            currencyCode: $currency->code(),
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug, 'list' => $priceListId], error: $result->error()->message);
        }

        return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug, 'list' => $priceListId], success: 'Price updated.');
    }
}
