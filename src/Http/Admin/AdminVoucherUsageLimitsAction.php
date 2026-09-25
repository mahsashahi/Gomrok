<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\SetVoucherUsageLimits\SetVoucherUsageLimitsCommand;
use Gomrok\Modules\Vouchers\Application\SetVoucherUsageLimits\SetVoucherUsageLimitsHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/vouchers/{voucherId}/usage-limits` (Phase 27) — the "Edit usage
 * limits" modal's submit target. Each of the three caps is nullable; a blank
 * field means unlimited for that dimension (`.claude/Voucher.md` §6).
 */
final readonly class AdminVoucherUsageLimitsAction
{
    use RedirectsToVouchers;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private SetVoucherUsageLimitsHandler $handler,
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
        $maxTotalRedemptions = AdminForm::nullableInt($body, 'max_total_redemptions');
        $maxPerUser = AdminForm::nullableInt($body, 'max_per_user');
        $maxPerClient = AdminForm::nullableInt($body, 'max_per_client');
        $submittedValues = ['id' => $voucherId, 'code' => $code, 'max_total_redemptions' => $maxTotalRedemptions, 'max_per_user' => $maxPerUser, 'max_per_client' => $maxPerClient];

        $result = $this->handler->handle(new SetVoucherUsageLimitsCommand(
            clientId: $activeClient->id,
            voucherId: $voucherId,
            maxTotalRedemptions: $maxTotalRedemptions,
            maxPerUser: $maxPerUser,
            maxPerClient: $maxPerClient,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->screen->reopen($request, $response, ['voucher' => $code], new AdminModalReopen('usage-limits', $submittedValues, $result->error()->message));
        }

        return $this->redirectToVouchers($response, ['voucher' => $code], success: 'Usage limits updated.');
    }
}
