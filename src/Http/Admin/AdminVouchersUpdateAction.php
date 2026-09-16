<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Vouchers\UpdateVoucherForAdmin\UpdateVoucherForAdminCommand;
use Gomrok\Modules\Admin\Application\Vouchers\UpdateVoucherForAdmin\UpdateVoucherForAdminHandler;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/vouchers/{voucherId}` (Phase 27) — the "Edit voucher" modal's
 * submit target: descriptive fields, validity window, default discount, and
 * the active/disabled toggle.
 */
final readonly class AdminVouchersUpdateAction
{
    use RedirectsToVouchers;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private UpdateVoucherForAdminHandler $handler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::VouchersUpdate)) {
            return AdminPermissionGuard::deny($response);
        }

        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        if ($activeClient === null) {
            return $this->redirectToVouchers($response, [], error: 'No active client.');
        }

        $voucherId = (int) $args['voucherId'];
        $body = AdminForm::body($request);
        $code = AdminForm::nullableStr($body, 'code');
        $minPurchaseCurrency = AdminForm::nullableStr($body, 'min_purchase_currency');

        [$minPurchaseMinor, $minPurchaseError] = AdminMoneyInput::parse(
            AdminForm::str($body, 'min_purchase_amount'),
            $minPurchaseCurrency ?? $activeClient->defaultCurrency,
        );
        if ($minPurchaseError !== null) {
            return $this->redirectToVouchers($response, ['voucher' => $code], error: $minPurchaseError);
        }

        $result = $this->handler->handle(new UpdateVoucherForAdminCommand(
            clientId: $activeClient->id,
            voucherId: $voucherId,
            name: AdminForm::str($body, 'name'),
            description: AdminForm::nullableStr($body, 'description'),
            validFrom: AdminForm::nullableStr($body, 'valid_from'),
            validUntil: AdminForm::nullableStr($body, 'valid_until'),
            firstPurchaseOnly: AdminForm::checked($body, 'first_purchase_only'),
            minPurchaseMinor: $minPurchaseMinor,
            minPurchaseCurrency: $minPurchaseMinor !== null ? ($minPurchaseCurrency ?? $activeClient->defaultCurrency) : null,
            defaultDiscountType: AdminForm::str($body, 'default_discount_type', 'none'),
            defaultPercentBp: AdminMoneyInput::percentToBasisPoints(AdminForm::str($body, 'default_percent')),
            active: AdminForm::checked($body, 'active'),
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToVouchers($response, ['voucher' => $code], error: $result->error()->message);
        }

        return $this->redirectToVouchers($response, ['voucher' => $code], success: 'Voucher updated.');
    }
}
