<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Vouchers\UpdateVoucherForAdmin\UpdateVoucherForAdminCommand;
use Gomrok\Modules\Admin\Application\Vouchers\UpdateVoucherForAdmin\UpdateVoucherForAdminHandler;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
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
        private AdminVouchersAction $screen,
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
        $name = AdminForm::str($body, 'name');
        $description = AdminForm::nullableStr($body, 'description');
        $validFrom = AdminForm::nullableStr($body, 'valid_from');
        $validUntil = AdminForm::nullableStr($body, 'valid_until');
        $firstPurchaseOnly = AdminForm::checked($body, 'first_purchase_only');
        $minPurchaseAmountRaw = AdminForm::str($body, 'min_purchase_amount');
        $minPurchaseCurrency = AdminForm::nullableStr($body, 'min_purchase_currency');
        $defaultDiscountType = AdminForm::str($body, 'default_discount_type', 'none');
        $defaultPercentRaw = AdminForm::str($body, 'default_percent');
        $active = AdminForm::checked($body, 'active');

        $submittedValues = [
            'id' => $voucherId,
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
            'active' => $active,
        ];

        [$minPurchaseMinor, $minPurchaseError] = AdminMoneyInput::parse(
            $minPurchaseAmountRaw,
            $minPurchaseCurrency ?? $activeClient->defaultCurrency,
        );
        if ($minPurchaseError !== null) {
            return $this->screen->reopen($request, $response, ['voucher' => $code], new AdminModalReopen('edit-voucher', $submittedValues, $minPurchaseError));
        }

        $result = $this->handler->handle(new UpdateVoucherForAdminCommand(
            clientId: $activeClient->id,
            voucherId: $voucherId,
            name: $name,
            description: $description,
            validFrom: $validFrom,
            validUntil: $validUntil,
            firstPurchaseOnly: $firstPurchaseOnly,
            minPurchaseMinor: $minPurchaseMinor,
            minPurchaseCurrency: $minPurchaseMinor !== null ? ($minPurchaseCurrency ?? $activeClient->defaultCurrency) : null,
            defaultDiscountType: $defaultDiscountType,
            defaultPercentBp: AdminMoneyInput::percentToBasisPoints($defaultPercentRaw),
            active: $active,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->screen->reopen($request, $response, ['voucher' => $code], new AdminModalReopen('edit-voucher', $submittedValues, $result->error()->message));
        }

        return $this->redirectToVouchers($response, ['voucher' => $code], success: 'Voucher updated.');
    }
}
