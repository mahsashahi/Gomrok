<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Packaging\UpdatePackageForAdmin\UpdatePackageForAdminCommand;
use Gomrok\Modules\Admin\Application\Packaging\UpdatePackageForAdmin\UpdatePackageForAdminHandler;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\AdminPermissionGuard;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/packaging/packages/{packageId}` (Phase 27 Increment B) — the
 * "Edit package" modal's submit target.
 */
final readonly class AdminPackagesUpdateAction
{
    use RedirectsToPackaging;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private UpdatePackageForAdminHandler $handler,
        private AdminPackagingAction $screen,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::PackagesUpdate)) {
            return AdminPermissionGuard::deny($response);
        }

        $packageId = (int) $args['packageId'];
        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        $defaultCurrency = $activeClient !== null ? $activeClient->defaultCurrency : 'USD';

        $body = AdminForm::body($request);
        $code = AdminForm::nullableStr($body, 'code');
        $name = AdminForm::str($body, 'name');
        $supportsOneTime = AdminForm::checked($body, 'supports_one_time');
        $supportsSubscription = AdminForm::checked($body, 'supports_subscription');
        $hasTrial = AdminForm::checked($body, 'has_trial');
        $highlighted = AdminForm::checked($body, 'highlighted');
        $active = AdminForm::checked($body, 'active');

        $durationMonths = AdminForm::nullableInt($body, 'duration_months');
        $trialDays = $hasTrial ? AdminForm::nullableInt($body, 'trial_days') : null;
        $badge = AdminForm::nullableStr($body, 'badge');
        $description = AdminForm::nullableStr($body, 'description');
        $priceAmountRaw = AdminForm::str($body, 'default_price_amount');
        $priceCurrencyRaw = AdminForm::str($body, 'default_price_currency', $defaultCurrency);

        $submittedValues = [
            'id' => $packageId,
            'code' => $code,
            'name' => $name,
            'description' => $description ?? '',
            'badge' => $badge ?? '',
            'highlighted' => $highlighted,
            'supports_one_time' => $supportsOneTime,
            'supports_subscription' => $supportsSubscription,
            'duration_months' => $durationMonths,
            'has_trial' => $hasTrial,
            'trial_days' => $trialDays,
            'default_price_amount' => $priceAmountRaw,
            'default_price_currency' => $priceCurrencyRaw,
            'active' => $active,
        ];

        [$priceAmountMinor, $priceCurrency, $priceError] = $this->parsePrice($priceAmountRaw, $priceCurrencyRaw);
        if ($priceError !== null) {
            return $this->screen->reopen($request, $response, ['tab' => 'packages', 'package' => $code], new AdminModalReopen('edit-package', $submittedValues, $priceError));
        }

        $result = $this->handler->handle(new UpdatePackageForAdminCommand(
            packageId: $packageId,
            name: $name,
            description: $description,
            badge: $badge,
            highlighted: $highlighted,
            supportsOneTime: $supportsOneTime,
            supportsSubscription: $supportsSubscription,
            durationMonths: $durationMonths,
            hasTrial: $hasTrial,
            trialDays: $trialDays,
            defaultPriceAmountMinor: $priceAmountMinor,
            defaultPriceCurrency: $priceAmountMinor !== null ? $priceCurrency : null,
            active: $active,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->screen->reopen($request, $response, ['tab' => 'packages', 'package' => $code], new AdminModalReopen('edit-package', $submittedValues, $result->error()->message));
        }

        return $this->redirectToPackaging($response, ['tab' => 'packages', 'package' => $code], success: 'Package updated.');
    }

    /**
     * @return array{0: ?int, 1: string, 2: ?string}
     */
    private function parsePrice(string $amount, string $currencyCode): array
    {
        if ($amount === '') {
            return [null, $currencyCode, null];
        }

        try {
            $currency = Currency::of($currencyCode);
        } catch (InvalidArgumentException) {
            return [null, $currencyCode, "'{$currencyCode}' is not a valid currency."];
        }

        $money = Money::fromDecimalInput($amount, $currency);
        if ($money === null) {
            return [null, $currencyCode, "'{$amount}' is not a valid price for {$currency->code()}."];
        }

        return [$money->toMinor(), $currency->code(), null];
    }
}
