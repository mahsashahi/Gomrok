<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Vouchers\Application\ValidateVoucher\ValidateVoucherCommand;
use Gomrok\Modules\Vouchers\Application\ValidateVoucher\ValidateVoucherHandler;
use Gomrok\Modules\Vouchers\Application\ValidateVoucher\ValidateVoucherResult;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/vouchers/validate?package=<code>&country=DE&code=WELCOME10[&device=][&method=][&purchase_type=][&interval=][&client_user_ref=][&first_purchase=true]`
 * (Phase 19 Q3/Q4) — a non-locking eligibility + discount preview. `GET`
 * rather than CLAUDE.md's suggested `POST` because it mutates nothing (no
 * reservation), the same reasoning already applied to `pricing/resolve` (Q4):
 * a pure read stays clear of the `/api/v1` write-idempotency requirement.
 */
final readonly class VouchersValidateAction
{
    public function __construct(
        private ClientContext $context,
        private ValidateVoucherHandler $handler,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $str = static fn (string $key): ?string => \is_string($query[$key] ?? null) && $query[$key] !== '' ? $query[$key] : null;

        $result = $this->handler->handle(new ValidateVoucherCommand(
            clientId: $this->context->clientId(),
            packageCode: $str('package') ?? '',
            country: $str('country') ?? '',
            voucherCode: $str('code') ?? '',
            deviceType: $str('device'),
            paymentMethod: $str('method'),
            purchaseType: $str('purchase_type'),
            subscriptionInterval: $str('interval'),
            clientUserRef: $str('client_user_ref'),
            isFirstPurchase: isset($query['first_purchase']) ? filter_var($query['first_purchase'], FILTER_VALIDATE_BOOLEAN) : null,
        ));

        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        $data = $result->value();
        \assert($data instanceof ValidateVoucherResult);

        $body = [
            'eligible' => $data->eligible,
            'reasons' => $data->reasons,
            'voucher' => [
                'code' => $data->voucherCode,
                'name' => $data->voucherName,
            ],
            'price' => [
                'amount_minor' => $data->priceAmountMinor,
                'amount' => $data->priceAmountDecimal,
                'currency' => $data->currencyCode,
            ],
        ];

        if ($data->eligible) {
            $body['discount'] = [
                'nominal_minor' => $data->nominalDiscountMinor,
                'applied_minor' => $data->appliedDiscountMinor,
                'payable_minor' => $data->payableMinor,
            ];
        }

        return $this->responder->json($response, $body);
    }
}
