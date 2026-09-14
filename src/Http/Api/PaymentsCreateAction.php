<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Checkout\Application\CreateCheckoutPayment\CreateCheckoutPaymentCommand;
use Gomrok\Modules\Checkout\Application\CreateCheckoutPayment\CreateCheckoutPaymentHandler;
use Gomrok\Modules\Checkout\Application\CreateCheckoutPayment\CreateCheckoutPaymentResult;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\IdempotencyContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/payments` (Phase 24) — the single end-to-end "create a
 * payment" call: authenticates (via the `/api/v1` group), resolves package /
 * price / voucher, resolves the provider / method / purchase type, creates
 * the real provider checkout session, and returns its redirect URL.
 * `{package}` accepts either the numeric `packages.id` or the package
 * `code`, matching `GET /api/v1/packages/{packageId}` (Phase 19 Q1).
 * Idempotent via the standard `Idempotency-Key` header (Phase 5/7) plus the
 * domain-level `attempt_reference` (Phase 18 Q1).
 */
final readonly class PaymentsCreateAction
{
    public function __construct(
        private ClientContext $context,
        private PackageDirectory $packages,
        private CreateCheckoutPaymentHandler $handler,
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

        if ($attemptReference === null || $packageRef === null || $country === null || $currency === null) {
            return $this->responder->problem($response, DomainError::validation(
                'payments.missing_fields',
                '"attempt_reference", "package", "country", and "currency" are required.',
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

        $result = $this->handler->handle(new CreateCheckoutPaymentCommand(
            clientId: $clientId,
            mode: $this->context->keyMode(),
            attemptReference: $attemptReference,
            packageId: $package->id,
            country: $country,
            currencyCode: $currency,
            clientUserRef: $str('client_user_ref'),
            purchaseType: $str('purchase_type'),
            paymentMethod: $str('payment_method'),
            subscriptionInterval: $str('subscription_interval'),
            deviceType: $str('device'),
            voucherCode: $str('voucher_code'),
            isFirstPurchase: $isFirstPurchase,
            customerEmail: $str('customer_email'),
        ));

        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        $value = $result->value();
        \assert($value instanceof CreateCheckoutPaymentResult);

        $this->idempotency->setTarget('checkout_attempt', $value->checkoutAttemptId);

        return $this->responder->json($response, [
            'checkout_attempt_id' => $value->checkoutAttemptId,
            'status' => $value->status,
            'redirect_url' => $value->redirectUrl,
            'provider_reference' => $value->providerReference,
        ], 201);
    }
}
