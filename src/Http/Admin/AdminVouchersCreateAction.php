<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherCommand;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherHandler;
use Gomrok\Shared\Http\AdminContext;
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
        $minPurchaseCurrency = AdminForm::nullableStr($body, 'min_purchase_currency');

        [$minPurchaseMinor, $minPurchaseError] = AdminMoneyInput::parse(
            AdminForm::str($body, 'min_purchase_amount'),
            $minPurchaseCurrency ?? $activeClient->defaultCurrency,
        );
        if ($minPurchaseError !== null) {
            return $this->redirectToVouchers($response, [], error: $minPurchaseError);
        }

        $result = $this->handler->handle(new CreateVoucherCommand(
            clientId: $activeClient->id,
            code: $code,
            name: AdminForm::str($body, 'name'),
            description: AdminForm::nullableStr($body, 'description'),
            validFrom: AdminForm::nullableStr($body, 'valid_from'),
            validUntil: AdminForm::nullableStr($body, 'valid_until'),
            firstPurchaseOnly: AdminForm::checked($body, 'first_purchase_only'),
            minPurchaseMinor: $minPurchaseMinor,
            minPurchaseCurrency: $minPurchaseMinor !== null ? ($minPurchaseCurrency ?? $activeClient->defaultCurrency) : null,
            defaultDiscountType: AdminForm::str($body, 'default_discount_type', 'none'),
            defaultPercentBp: AdminMoneyInput::percentToBasisPoints(AdminForm::str($body, 'default_percent')),
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToVouchers($response, [], error: $result->error()->message);
        }

        return $this->redirectToVouchers($response, ['voucher' => strtoupper($code)], success: 'Voucher created.');
    }
}
