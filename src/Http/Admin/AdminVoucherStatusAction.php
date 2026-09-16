<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\ChangeVoucherStatus\ChangeVoucherStatusHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/vouchers/{voucherId}/status` (Phase 27) — the enable/disable
 * toggle. CLAUDE.md lists `vouchers.disable` as its own permission key
 * distinct from `vouchers.update`, so the two directions are gated
 * separately rather than both on update.
 */
final readonly class AdminVoucherStatusAction
{
    use RedirectsToVouchers;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private ChangeVoucherStatusHandler $handler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = AdminForm::body($request);
        $enable = AdminForm::str($body, 'action') === 'enable';

        $required = $enable ? AdminPermission::VouchersUpdate : AdminPermission::VouchersDisable;
        if (!AdminPermissionGuard::allows($this->context, $required)) {
            return AdminPermissionGuard::deny($response);
        }

        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        if ($activeClient === null) {
            return $this->redirectToVouchers($response, [], error: 'No active client.');
        }

        $voucherId = (int) $args['voucherId'];
        $code = AdminForm::nullableStr($body, 'code');

        $result = $enable
            ? $this->handler->enable($voucherId, $activeClient->id, $this->context->admin()->id)
            : $this->handler->disable($voucherId, $activeClient->id, $this->context->admin()->id);

        if ($result->isErr()) {
            return $this->redirectToVouchers($response, ['voucher' => $code], error: $result->error()->message);
        }

        return $this->redirectToVouchers($response, ['voucher' => $code], success: $enable ? 'Voucher enabled.' : 'Voucher disabled.');
    }
}
