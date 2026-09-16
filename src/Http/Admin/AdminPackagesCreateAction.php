<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Packaging\CreatePackageForAdmin\CreatePackageForAdminCommand;
use Gomrok\Modules\Admin\Application\Packaging\CreatePackageForAdmin\CreatePackageForAdminHandler;
use Gomrok\Modules\Admin\Application\Packaging\CreatePackageForAdmin\CreatePackageForAdminResult;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/packaging/packages` (Phase 27 Increment B) — the "Create
 * package" modal's submit target. Scoped to the admin panel's active client
 * (never a client id trusted from the form).
 */
final readonly class AdminPackagesCreateAction
{
    use RedirectsToPackaging;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private CreatePackageForAdminHandler $handler,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::PackagesCreate)) {
            return AdminPermissionGuard::deny($response);
        }

        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        if ($activeClient === null) {
            return $this->redirectToPackaging($response, ['tab' => 'packages'], error: 'No active client.');
        }

        $body = AdminForm::body($request);
        $code = AdminForm::str($body, 'code');
        $name = AdminForm::str($body, 'name');
        $supportsOneTime = AdminForm::checked($body, 'supports_one_time');
        $supportsSubscription = AdminForm::checked($body, 'supports_subscription');
        $hasTrial = AdminForm::checked($body, 'has_trial');
        $highlighted = AdminForm::checked($body, 'highlighted');

        $durationMonths = AdminForm::nullableInt($body, 'duration_months');
        $trialDays = $hasTrial ? AdminForm::nullableInt($body, 'trial_days') : null;
        $badge = AdminForm::nullableStr($body, 'badge');
        $description = AdminForm::nullableStr($body, 'description');

        [$priceAmountMinor, $priceCurrency, $priceError] = $this->parsePrice(
            AdminForm::str($body, 'default_price_amount'),
            AdminForm::str($body, 'default_price_currency', $activeClient->defaultCurrency),
        );
        if ($priceError !== null) {
            return $this->redirectToPackaging($response, ['tab' => 'packages'], error: $priceError);
        }

        $result = $this->handler->handle(new CreatePackageForAdminCommand(
            clientId: $activeClient->id,
            code: $code,
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
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToPackaging($response, ['tab' => 'packages'], error: $result->error()->message);
        }

        $value = $result->value();
        \assert($value instanceof CreatePackageForAdminResult);

        return $this->redirectToPackaging($response, ['tab' => 'packages', 'package' => $value->code], success: 'Package created.');
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
