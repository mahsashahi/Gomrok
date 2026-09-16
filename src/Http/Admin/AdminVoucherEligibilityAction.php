<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\SetVoucherEligibility\SetVoucherEligibilityCommand;
use Gomrok\Modules\Vouchers\Application\SetVoucherEligibility\SetVoucherEligibilityHandler;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityDimension;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/vouchers/{voucherId}/eligibility` (Phase 27) — the "Edit
 * eligibility" modal's submit target. The form carries one comma-separated
 * field per {@see VoucherEligibilityDimension}; they are flattened into the
 * `{dimension, value}` rule list {@see SetVoucherEligibilityHandler} expects,
 * which is a **full replace** — an emptied field drops that dimension's rules,
 * making it unrestricted again (`.claude/Voucher.md` §5).
 */
final readonly class AdminVoucherEligibilityAction
{
    use RedirectsToVouchers;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private SetVoucherEligibilityHandler $handler,
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

        $rules = [];
        foreach (VoucherEligibilityDimension::cases() as $dimension) {
            foreach (self::splitList(AdminForm::str($body, $dimension->value)) as $value) {
                $rules[] = ['dimension' => $dimension->value, 'value' => $value];
            }
        }

        $result = $this->handler->handle(new SetVoucherEligibilityCommand(
            clientId: $activeClient->id,
            voucherId: $voucherId,
            rules: $rules,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToVouchers($response, ['voucher' => $code], error: $result->error()->message);
        }

        return $this->redirectToVouchers($response, ['voucher' => $code], success: 'Eligibility rules updated.');
    }

    /**
     * @return list<string>
     */
    private static function splitList(string $raw): array
    {
        $parts = preg_split('/[,\s]+/', $raw);
        $parts = $parts !== false ? $parts : [];

        return array_values(array_filter(array_map(static fn (string $v): string => trim($v), $parts), static fn (string $v): bool => $v !== ''));
    }
}
