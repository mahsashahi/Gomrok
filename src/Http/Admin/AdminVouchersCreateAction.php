<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherCommand;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/vouchers` (Phase 27) — the "Create voucher" modal's submit
 * target. Eligibility rules, usage caps and per-currency overrides are set
 * afterwards through their own modals, matching how the underlying handlers
 * are split (`.claude/Voucher.md` §11 / Phase 16 Q5).
 */
final readonly class AdminVouchersCreateAction
{
    use RedirectsToVouchers;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private CreateVoucherHandler $handler,
        private AdminVouchersAction $screen,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::VouchersCreate)) {
            return AdminPermissionGuard::deny($response);
        }

        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        if ($activeClient === null) {
            return $this->redirectToVouchers($response, [], error: 'No active client.');
        }

        $body = AdminForm::body($request);
        $code = AdminForm::str($body, 'code');
        $name = AdminForm::str($body, 'name');
        $description = AdminForm::nullableStr($body, 'description');
        $validFrom = AdminForm::nullableStr($body, 'valid_from');
        $validUntil = AdminForm::nullableStr($body, 'valid_until');
        $firstPurchaseOnly = AdminForm::checked($body, 'first_purchase_only');
        $minPurchaseAmountRaw = AdminForm::str($body, 'min_purchase_amount');
        $minPurchaseCurrency = AdminForm::nullableStr($body, 'min_purchase_currency');
        $defaultDiscountType = AdminForm::str($body, 'default_discount_type', 'none');
        $defaultPercentRaw = AdminForm::str($body, 'default_percent');

        $submittedValues = [
            'code' => $code,
            'name' => $name,
            'description' => $description ?? '',
            'valid_from' => $validFrom ?? '',
            'valid_until' => $validUntil ?? '',
            'first_purchase_only' => $firstPurchaseOnly,
            'min_purchase_amount' => $minPurchaseAmountRaw,
            'min_purchase_currency' => $minPurchaseCurrency ?? $activeClient->defaultCurrency,
            'default_discount_type' => $defaultDiscountType,
            'default_percent' => $defaultPercentRaw,
        ];

        [$minPurchaseMinor, $minPurchaseError] = AdminMoneyInput::parse(
            $minPurchaseAmountRaw,
            $minPurchaseCurrency ?? $activeClient->defaultCurrency,
        );
        if ($minPurchaseError !== null) {
            return $this->screen->reopen($request, $response, [], new AdminModalReopen('create-voucher', $submittedValues, $minPurchaseError));
        }

        $result = $this->handler->handle(new CreateVoucherCommand(
            clientId: $activeClient->id,
            code: $code,
            name: $name,
            description: $description,
            validFrom: $validFrom,
            validUntil: $validUntil,
            firstPurchaseOnly: $firstPurchaseOnly,
            minPurchaseMinor: $minPurchaseMinor,
            minPurchaseCurrency: $minPurchaseMinor !== null ? ($minPurchaseCurrency ?? $activeClient->defaultCurrency) : null,
            defaultDiscountType: $defaultDiscountType,
            defaultPercentBp: AdminMoneyInput::percentToBasisPoints($defaultPercentRaw),
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->screen->reopen($request, $response, [], new AdminModalReopen('create-voucher', $submittedValues, $result->error()->message));
        }

        return $this->redirectToVouchers($response, ['voucher' => strtoupper($code)], success: 'Voucher created.');
    }
}
