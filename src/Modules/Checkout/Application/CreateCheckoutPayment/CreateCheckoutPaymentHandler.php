<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateCheckoutPayment;

use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptCommand;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptHandler;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptResult;
use Gomrok\Modules\Checkout\Application\CreateProviderCheckout\CreateProviderCheckoutCommand;
use Gomrok\Modules\Checkout\Application\CreateProviderCheckout\CreateProviderCheckoutHandler;
use Gomrok\Modules\Checkout\Application\CreateProviderCheckout\CreateProviderCheckoutResult;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherCommand;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherHandler;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingCommand;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingHandler;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderCommand;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderHandler;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;

/**
 * Composes the whole pre-payment pipeline into the single
 * `POST /api/v1/payments` call (Phase 24): start the checkout attempt,
 * resolve pricing, reserve a voucher when one was supplied, select the
 * provider, then create the real provider checkout session. Each inner
 * handler is already idempotent by its own domain key (`attempt_reference`
 * for creation, `checkout_attempt_id` for the rest), so retrying this whole
 * command with the same `attempt_reference` naturally replays every step up
 * to wherever it previously got to — **except** the last step
 * ({@see CreateProviderCheckoutHandler}), which rejects a second call once
 * the attempt has moved past `provider_selected` rather than replaying the
 * previous redirect. The primary defense against a genuine duplicate
 * request is the HTTP-level `Idempotency-Key` header (Phase 5/7); this is a
 * known, accepted gap for the unusual case of retrying with a *different*
 * idempotency key but the same `attempt_reference`.
 *
 * A `subscription` purchase type is rejected before any of this runs — that
 * belongs to `POST /api/v1/subscriptions` (Phase 26), not here.
 */
final readonly class CreateCheckoutPaymentHandler
{
    public function __construct(
        private CreateCheckoutAttemptHandler $createAttempt,
        private ResolveCheckoutPricingHandler $resolvePricing,
        private ReserveCheckoutVoucherHandler $reserveVoucher,
        private SelectCheckoutProviderHandler $selectProvider,
        private CreateProviderCheckoutHandler $createProviderCheckout,
    ) {
    }

    public function handle(CreateCheckoutPaymentCommand $command): Result
    {
        if ($command->purchaseType === PurchaseType::Subscription->value) {
            return Result::err(DomainError::validation(
                'checkout_payment.subscription_not_supported_here',
                'Subscriptions are created via POST /api/v1/subscriptions, not this endpoint.',
            ));
        }

        $attemptResult = $this->createAttempt->handle(new CreateCheckoutAttemptCommand(
            clientId: $command->clientId,
            attemptReference: $command->attemptReference,
            packageId: $command->packageId,
            country: $command->country,
            currencyCode: $command->currencyCode,
            clientUserRef: $command->clientUserRef,
            purchaseType: $command->purchaseType ?? PurchaseType::OneTimePayment->value,
            paymentMethod: $command->paymentMethod,
            subscriptionInterval: $command->subscriptionInterval,
            actorId: $command->actorId,
        ));
        if ($attemptResult->isErr()) {
            return $attemptResult;
        }
        $attempt = $attemptResult->value();
        \assert($attempt instanceof CreateCheckoutAttemptResult);
        $attemptId = $attempt->checkoutAttemptId;

        $pricingResult = $this->resolvePricing->handle(new ResolveCheckoutPricingCommand(
            checkoutAttemptId: $attemptId,
            clientId: $command->clientId,
            deviceType: $command->deviceType,
            actorId: $command->actorId,
        ));
        if ($pricingResult->isErr()) {
            return $pricingResult;
        }

        if ($command->voucherCode !== null && trim($command->voucherCode) !== '') {
            $voucherResult = $this->reserveVoucher->handle(new ReserveCheckoutVoucherCommand(
                checkoutAttemptId: $attemptId,
                clientId: $command->clientId,
                voucherCode: $command->voucherCode,
                clientUserRef: $command->clientUserRef,
                isFirstPurchase: $command->isFirstPurchase,
                actorId: $command->actorId,
            ));
            if ($voucherResult->isErr()) {
                return $voucherResult;
            }
        }

        $providerResult = $this->selectProvider->handle(new SelectCheckoutProviderCommand(
            checkoutAttemptId: $attemptId,
            clientId: $command->clientId,
            mode: $command->mode,
            deviceType: $command->deviceType,
            actorId: $command->actorId,
        ));
        if ($providerResult->isErr()) {
            return $providerResult;
        }

        $checkoutResult = $this->createProviderCheckout->handle(new CreateProviderCheckoutCommand(
            clientId: $command->clientId,
            checkoutAttemptId: $attemptId,
            customerEmail: $command->customerEmail,
            actorId: $command->actorId,
        ));
        if ($checkoutResult->isErr()) {
            return $checkoutResult;
        }
        $checkout = $checkoutResult->value();
        \assert($checkout instanceof CreateProviderCheckoutResult);

        return Result::ok(new CreateCheckoutPaymentResult($attemptId, $checkout->status, $checkout->redirectUrl, $checkout->providerReference));
    }
}
