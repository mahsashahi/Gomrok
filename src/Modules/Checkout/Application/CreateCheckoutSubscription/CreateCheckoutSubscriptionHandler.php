<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateCheckoutSubscription;

use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptCommand;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptHandler;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptResult;
use Gomrok\Modules\Checkout\Application\CreateProviderSubscription\CreateProviderSubscriptionCommand;
use Gomrok\Modules\Checkout\Application\CreateProviderSubscription\CreateProviderSubscriptionHandler;
use Gomrok\Modules\Checkout\Application\CreateProviderSubscription\CreateProviderSubscriptionResult;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherCommand;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherHandler;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingCommand;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingHandler;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderCommand;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderHandler;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Domain\Result;

/**
 * The subscription counterpart to `CreateCheckoutPaymentHandler` (Phase 26
 * Q1) — composes the exact same pipeline (start attempt, resolve pricing,
 * optionally reserve a voucher, select the provider), with `purchaseType`
 * pinned to `subscription` and the final step delegated to
 * `CreateProviderSubscriptionHandler` instead of `CreateProviderCheckoutHandler`.
 */
final readonly class CreateCheckoutSubscriptionHandler
{
    public function __construct(
        private CreateCheckoutAttemptHandler $createAttempt,
        private ResolveCheckoutPricingHandler $resolvePricing,
        private ReserveCheckoutVoucherHandler $reserveVoucher,
        private SelectCheckoutProviderHandler $selectProvider,
        private CreateProviderSubscriptionHandler $createProviderSubscription,
    ) {
    }

    public function handle(CreateCheckoutSubscriptionCommand $command): Result
    {
        $attemptResult = $this->createAttempt->handle(new CreateCheckoutAttemptCommand(
            clientId: $command->clientId,
            attemptReference: $command->attemptReference,
            packageId: $command->packageId,
            country: $command->country,
            currencyCode: $command->currencyCode,
            clientUserRef: $command->clientUserRef,
            purchaseType: PurchaseType::Subscription->value,
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

        $subscriptionResult = $this->createProviderSubscription->handle(new CreateProviderSubscriptionCommand(
            clientId: $command->clientId,
            checkoutAttemptId: $attemptId,
            customerEmail: $command->customerEmail,
            actorId: $command->actorId,
        ));
        if ($subscriptionResult->isErr()) {
            return $subscriptionResult;
        }
        $created = $subscriptionResult->value();
        \assert($created instanceof CreateProviderSubscriptionResult);

        return Result::ok(new CreateCheckoutSubscriptionResult($attemptId, $created->status, $created->redirectUrl, $created->providerReference));
    }
}
