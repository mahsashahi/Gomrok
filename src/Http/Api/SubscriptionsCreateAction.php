<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Checkout\Application\CreateCheckoutSubscription\CreateCheckoutSubscriptionCommand;
use Gomrok\Modules\Checkout\Application\CreateCheckoutSubscription\CreateCheckoutSubscriptionHandler;
use Gomrok\Modules\Checkout\Application\CreateCheckoutSubscription\CreateCheckoutSubscriptionResult;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\IdempotencyContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/subscriptions` (Phase 26) — the subscription counterpart to
 * `PaymentsCreateAction`. `client_user_ref` and `subscription_interval` are
 * both required (Q4) — a subscription is created client-user-less or
 * interval-less nowhere in this API. `{package}` accepts either the numeric
 * `packages.id` or the package `code`, matching `PaymentsCreateAction`.
 */
final readonly class SubscriptionsCreateAction
{
    public function __construct(
        private ClientContext $context,
        private PackageDirectory $packages,
        private CreateCheckoutSubscriptionHandler $handler,
        private IdempotencyContext $idempotency,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $clientId = $this->context->clientId();
        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];

        $str = static fn (string $key): ?string => \is_string($body[$key] ?? null) && trim($body[$key]) !== '' ? trim($body[$key]) : null;

        $attemptReference = $str('attempt_reference');
        $packageRef = $str('package');
        $country = $str('country');
        $currency = $str('currency');
        $clientUserRef = $str('client_user_ref');
        $subscriptionInterval = $str('subscription_interval');

        if ($attemptReference === null || $packageRef === null || $country === null || $currency === null || $clientUserRef === null || $subscriptionInterval === null) {
            return $this->responder->problem($response, DomainError::validation(
                'subscriptions.missing_fields',
                '"attempt_reference", "package", "country", "currency", "client_user_ref", and "subscription_interval" are required.',
            ));
        }

        $package = ctype_digit($packageRef)
            ? $this->packages->findById((int) $packageRef)
            : $this->packages->find($clientId, $packageRef);
        if ($package === null || $package->clientId !== $clientId) {
            return $this->responder->problem($response, DomainError::notFound('package.not_found', "Package '{$packageRef}' was not found."));
        }

        $isFirstPurchase = null;
        if (\array_key_exists('first_purchase', $body)) {
            $isFirstPurchase = filter_var($body['first_purchase'], \FILTER_VALIDATE_BOOLEAN);
        }

        $result = $this->handler->handle(new CreateCheckoutSubscriptionCommand(
            clientId: $clientId,
            mode: $this->context->keyMode(),
            attemptReference: $attemptReference,
            packageId: $package->id,
            country: $country,
            currencyCode: $currency,
            clientUserRef: $clientUserRef,
            subscriptionInterval: $subscriptionInterval,
            paymentMethod: $str('payment_method'),
            deviceType: $str('device'),
            voucherCode: $str('voucher_code'),
            isFirstPurchase: $isFirstPurchase,
            customerEmail: $str('customer_email'),
        ));

        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        $value = $result->value();
        \assert($value instanceof CreateCheckoutSubscriptionResult);

        $this->idempotency->setTarget('checkout_attempt', $value->checkoutAttemptId);

        return $this->responder->json($response, [
            'checkout_attempt_id' => $value->checkoutAttemptId,
            'status' => $value->status,
            'redirect_url' => $value->redirectUrl,
            'provider_reference' => $value->providerReference,
        ], 201);
    }
}
