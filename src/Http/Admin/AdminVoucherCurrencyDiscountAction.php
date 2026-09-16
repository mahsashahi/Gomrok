<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\SetVoucherCurrencyDiscount\SetVoucherCurrencyDiscountCommand;
use Gomrok\Modules\Vouchers\Application\SetVoucherCurrencyDiscount\SetVoucherCurrencyDiscountHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/vouchers/{voucherId}/currency-discounts` (Phase 27) — upserts
 * one per-currency discount override. Which of `amount` / `percent` /
 * `max_discount` is meaningful depends on the chosen type; the domain guards
 * in `.claude/Voucher.md` §4 reject the invalid combinations, so this action
 * forwards only the fields that type allows rather than second-guessing them.
 */
final readonly class AdminVoucherCurrencyDiscountAction
{
    use RedirectsToVouchers;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private SetVoucherCurrencyDiscountHandler $handler,
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
        $currency = AdminForm::str($body, 'currency');
        $type = AdminForm::str($body, 'discount_type', 'fixed');

        $amountMinor = null;
        $maxDiscountMinor = null;
        $percentBp = null;

        if ($type === 'fixed') {
            [$amountMinor, $error] = AdminMoneyInput::parse(AdminForm::str($body, 'amount'), $currency);
            if ($error !== null) {
                return $this->redirectToVouchers($response, ['voucher' => $code], error: $error);
            }
        } elseif ($type === 'percentage') {
            $percentBp = AdminMoneyInput::percentToBasisPoints(AdminForm::str($body, 'percent'));
            [$maxDiscountMinor, $error] = AdminMoneyInput::parse(AdminForm::str($body, 'max_discount'), $currency);
            if ($error !== null) {
                return $this->redirectToVouchers($response, ['voucher' => $code], error: $error);
            }
        }

        $result = $this->handler->handle(new SetVoucherCurrencyDiscountCommand(
            clientId: $activeClient->id,
            voucherId: $voucherId,
            currencyCode: $currency,
            discountType: $type,
            percentBp: $percentBp,
            amountMinor: $amountMinor,
            maxDiscountMinor: $maxDiscountMinor,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToVouchers($response, ['voucher' => $code], error: $result->error()->message);
        }

        return $this->redirectToVouchers($response, ['voucher' => $code], success: 'Currency override saved.');
    }
}
