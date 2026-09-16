<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\RemoveVoucherCurrencyDiscount\RemoveVoucherCurrencyDiscountHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/vouchers/{voucherId}/currency-discounts/{currency}/remove`
 * (Phase 27) — drops one per-currency override. The voucher then falls back to
 * its default discount in that currency, or becomes inapplicable there if the
 * default is `none` (`.claude/Voucher.md` §4).
 */
final readonly class AdminVoucherCurrencyDiscountRemoveAction
{
    use RedirectsToVouchers;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private RemoveVoucherCurrencyDiscountHandler $handler,
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
        $currency = $args['currency'];
        $body = AdminForm::body($request);
        $code = AdminForm::nullableStr($body, 'code');

        $result = $this->handler->handle($voucherId, $currency, $activeClient->id, $this->context->admin()->id);
        if ($result->isErr()) {
            return $this->redirectToVouchers($response, ['voucher' => $code], error: $result->error()->message);
        }

        return $this->redirectToVouchers($response, ['voucher' => $code], success: 'Currency override removed.');
    }
}
