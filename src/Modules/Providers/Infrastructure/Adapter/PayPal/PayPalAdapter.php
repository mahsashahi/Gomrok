<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure\Adapter\PayPal;

use Brick\Money\Money as BrickMoney;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand;
use Gomrok\Modules\Providers\Application\Adapter\ParsedWebhookEvent;
use Gomrok\Modules\Providers\Application\Adapter\PaymentProviderPort;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAuthenticationFailed;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRefundResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRequestFailed;
use Gomrok\Modules\Providers\Application\Adapter\ProviderWebhookVerificationFailed;
use Gomrok\Modules\Providers\Application\Adapter\RawWebhook;
use Gomrok\Modules\Providers\Application\Adapter\SupportsAuthCapture;
use Gomrok\Modules\Providers\Application\Adapter\SupportsRefunds;
use Gomrok\Modules\Providers\Domain\ProviderCapabilities;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclarations;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * PayPal implements the core port plus `SupportsAuthCapture` and
 * `SupportsRefunds` — **not** `SupportsSubscriptions` this phase (Phase 22
 * Q8: PayPal Subscriptions need a persisted Billing "Plan" resource created
 * ahead of time, unlike Stripe/Mollie's ad-hoc pricing; deferred until a
 * pre-provisioning/caching design is chosen). Talks to PayPal's REST API
 * directly over Guzzle (Phase 22 Q2 — no official SDK), used only here, per
 * Hexagonal Architecture Rule 5. `getCapabilities()` defers to the Phase 8
 * seeded declaration, same as the other adapters.
 *
 * No access token caching: a fresh token is fetched via client-credentials
 * OAuth2 on every call that needs one. Matches the established "adapters are
 * built fresh per call, construction/setup cost is negligible" design (Phase
 * 21 Q4) — the cost here is one extra HTTP round trip per operation, traded
 * for a simpler, `final readonly` class with no mutable cache to reason
 * about.
 */
final readonly class PayPalAdapter implements
    PaymentProviderPort,
    SupportsAuthCapture,
    SupportsRefunds
{
    private const PROVIDER_TYPE_CODE = 'paypal';

    public function __construct(
        private ClientInterface $http,
        private string $clientId,
        private string $clientSecret,
        private string $baseUri,
        private ProviderTypeDeclarations $declarations,
        private PayPalStatusMapper $mapper = new PayPalStatusMapper(),
    ) {
    }

    public function createPayment(CreatePaymentCommand $command): ProviderPaymentResult
    {
        return $this->createOrder($command, 'CAPTURE');
    }

    public function authorizePayment(CreatePaymentCommand $command): ProviderPaymentResult
    {
        return $this->createOrder($command, 'AUTHORIZE');
    }

    /**
     * Phase 22 Q7 — PayPal's Orders API is a two-step redirect flow
     * regardless of intent: the customer must approve the order before
     * either a capture-intent order can be captured or an authorize-intent
     * order can be authorized. This method inspects the order's own `intent`
     * (fetched fresh) and performs whichever real dance that intent needs:
     * a capture-intent order gets one `/capture` call; an authorize-intent
     * order gets `/authorize` (creating the Authorization resource) followed
     * by `/authorizations/{id}/capture` — callers always call this one
     * method regardless of which flow created the order.
     */
    public function capturePayment(string $providerReference, ?int $amountMinor = null): ProviderPaymentResult
    {
        $order = $this->request('GET', "/v2/checkout/orders/{$providerReference}");
        $intent = \is_string($order['intent'] ?? null) ? $order['intent'] : 'CAPTURE';

        if ($intent === 'AUTHORIZE') {
            return $this->authorizeThenCapture($providerReference, $order, $amountMinor);
        }

        $captured = $this->request('POST', "/v2/checkout/orders/{$providerReference}/capture", []);

        return new ProviderPaymentResult($providerReference, '', $this->stringValue($captured['status'] ?? 'COMPLETED'));
    }

    /**
     * Phase 22 Q7 — PayPal has no "cancel this order" endpoint; an
     * unauthorized order simply lapses on its own. The only real cancel
     * action available is voiding an existing Authorization, so this voids
     * one if the order has one, and throws (rather than silently succeeding)
     * when it doesn't — there is genuinely nothing for the API to cancel yet.
     */
    public function cancelPayment(string $providerReference): void
    {
        $order = $this->request('GET', "/v2/checkout/orders/{$providerReference}");
        $authorizationId = $this->findAuthorizationId($order);

        if ($authorizationId === null) {
            throw new ProviderRequestFailed("PayPal order {$providerReference} has no authorization to void — an unauthorized order cannot be cancelled via the API; it lapses on its own.");
        }

        $this->request('POST', "/v2/payments/authorizations/{$authorizationId}/void", []);
    }

    public function getPaymentStatus(string $providerReference): ProviderPaymentStatus
    {
        $order = $this->request('GET', "/v2/checkout/orders/{$providerReference}");
        $status = $this->stringValue($order['status'] ?? 'CREATED');

        return new ProviderPaymentStatus($providerReference, $status, $this->mapper->fromStatus($status), $this->findCaptureId($order));
    }

    public function verifyWebhookSignature(RawWebhook $webhook): bool
    {
        try {
            $result = $this->request('POST', '/v1/notifications/verify-webhook-signature', $this->verificationPayload($webhook));
        } catch (ProviderAuthenticationFailed|ProviderRequestFailed) {
            return false;
        }

        return ($result['verification_status'] ?? null) === 'SUCCESS';
    }

    public function parseWebhook(RawWebhook $webhook): ParsedWebhookEvent
    {
        if (!$this->verifyWebhookSignature($webhook)) {
            throw new ProviderWebhookVerificationFailed('PayPal webhook signature did not verify.');
        }

        $payload = json_decode($webhook->payload, true);
        if (!\is_array($payload)) {
            throw new ProviderWebhookVerificationFailed('PayPal webhook payload was not valid JSON.');
        }

        $resource = \is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $resourceId = $resource['id'] ?? null;
        $resourceStatus = $resource['status'] ?? null;

        return new ParsedWebhookEvent(
            $this->stringValue($payload['id'] ?? null),
            $this->stringValue($payload['event_type'] ?? null),
            \is_string($resourceId) ? $resourceId : null,
            \is_string($resourceStatus) ? $resourceStatus : '',
            $payload,
        );
    }

    public function mapProviderStatusToInternalStatus(string $providerStatus): PaymentStatus
    {
        return $this->mapper->fromStatus($providerStatus);
    }

    public function getCapabilities(): ProviderCapabilities
    {
        $declaration = $this->declarations->findByCode(self::PROVIDER_TYPE_CODE);
        if ($declaration === null) {
            return ProviderCapabilities::none();
        }

        return $declaration->capabilities;
    }

    /**
     * PayPal refunds operate on a **capture** id, not an order id — the same
     * "deeper reference" `getPaymentStatus()` surfaces via
     * `ProviderPaymentStatus::$paymentIntentReference`, mirroring exactly how
     * `StripeAdapter::refundPayment()` takes a PaymentIntent id rather than a
     * Checkout Session id.
     */
    public function refundPayment(string $providerReference, ?int $amountMinor = null): ProviderRefundResult
    {
        $payload = [];
        if ($amountMinor !== null) {
            $capture = $this->request('GET', "/v2/payments/captures/{$providerReference}");
            $currency = $this->digString($capture, 'amount', 'currency_code') ?? throw new ProviderRequestFailed('PayPal capture response did not contain a currency code.');
            $payload['amount'] = $this->toPayPalAmount($amountMinor, $currency);
        }

        $refund = $this->request('POST', "/v2/payments/captures/{$providerReference}/refund", $payload);

        $refundedMinor = $amountMinor ?? $this->fromPayPalAmount($refund['amount'] ?? null);

        return new ProviderRefundResult($this->stringValue($refund['id'] ?? null), $refundedMinor, $this->stringValue($refund['status'] ?? ''));
    }

    private function createOrder(CreatePaymentCommand $command, string $intent): ProviderPaymentResult
    {
        $order = $this->request('POST', '/v2/checkout/orders', [
            'intent' => $intent,
            'purchase_units' => [[
                'reference_id' => $command->attemptReference,
                'description' => $command->description,
                'amount' => $this->toPayPalAmount($command->amountMinor, $command->currencyCode),
            ]],
            'application_context' => [
                'return_url' => $command->successUrl,
                'cancel_url' => $command->cancelUrl,
            ],
        ]);

        return new ProviderPaymentResult(
            $this->stringValue($order['id'] ?? null),
            $this->approveLink($order),
            $this->stringValue($order['status'] ?? 'CREATED'),
        );
    }

    /**
     * @param array<array-key, mixed> $order
     */
    private function authorizeThenCapture(string $orderId, array $order, ?int $amountMinor): ProviderPaymentResult
    {
        $authorizationId = $this->findAuthorizationId($order);
        if ($authorizationId === null) {
            $authorizeResult = $this->request('POST', "/v2/checkout/orders/{$orderId}/authorize", []);
            $authorizationId = $this->findAuthorizationId($authorizeResult);
        }

        if ($authorizationId === null) {
            throw new ProviderRequestFailed("PayPal did not return an authorization id for order {$orderId}.");
        }

        $payload = [];
        if ($amountMinor !== null) {
            $currency = $this->digString($order, 'purchase_units', 0, 'amount', 'currency_code') ?? throw new ProviderRequestFailed("PayPal order {$orderId} did not contain a currency code.");
            $payload['amount'] = $this->toPayPalAmount($amountMinor, $currency);
        }
        $payload['final_capture'] = true;

        $captured = $this->request('POST', "/v2/payments/authorizations/{$authorizationId}/capture", $payload);

        return new ProviderPaymentResult($authorizationId, '', $this->stringValue($captured['status'] ?? 'COMPLETED'));
    }

    /**
     * @return array<string, mixed>
     */
    private function verificationPayload(RawWebhook $webhook): array
    {
        return [
            'auth_algo' => $webhook->headers['PAYPAL-AUTH-ALGO'] ?? '',
            'cert_url' => $webhook->headers['PAYPAL-CERT-URL'] ?? '',
            'transmission_id' => $webhook->headers['PAYPAL-TRANSMISSION-ID'] ?? '',
            'transmission_sig' => $webhook->headers['PAYPAL-TRANSMISSION-SIG'] ?? '',
            'transmission_time' => $webhook->headers['PAYPAL-TRANSMISSION-TIME'] ?? '',
            'webhook_id' => $webhook->webhookSigningSecret,
            'webhook_event' => json_decode($webhook->payload, true) ?? [],
        ];
    }

    /**
     * @param array<array-key, mixed> $order
     */
    private function findAuthorizationId(array $order): ?string
    {
        $id = $this->digString($order, 'purchase_units', 0, 'payments', 'authorizations', 0, 'id');

        return $id !== '' ? $id : null;
    }

    /**
     * @param array<array-key, mixed> $order
     */
    private function findCaptureId(array $order): ?string
    {
        $id = $this->digString($order, 'purchase_units', 0, 'payments', 'captures', 0, 'id');

        return $id !== '' ? $id : null;
    }

    /**
     * Safely walks a chain of array keys/indexes through a decoded JSON
     * structure, where every intermediate value is `mixed` as far as
     * PHPStan is concerned — returns `null` the moment any step isn't an
     * array or doesn't hold that key, rather than accessing an offset on a
     * value that might not support it.
     *
     * @param array<array-key, mixed> $data
     */
    private function dig(array $data, int|string ...$path): mixed
    {
        $current = $data;
        foreach ($path as $key) {
            if (!\is_array($current) || !\array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function digString(array $data, int|string ...$path): ?string
    {
        $value = $this->dig($data, ...$path);

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $resource
     */
    private function approveLink(array $resource): string
    {
        foreach ((\is_array($resource['links'] ?? null) ? $resource['links'] : []) as $link) {
            if (\is_array($link) && ($link['rel'] ?? null) === 'approve' && \is_string($link['href'] ?? null)) {
                return $link['href'];
            }
        }

        return '';
    }

    /**
     * @return array{currency_code: string, value: string}
     */
    private function toPayPalAmount(int $amountMinor, string $currencyCode): array
    {
        return [
            'currency_code' => strtoupper($currencyCode),
            'value' => Money::fromMinor($amountMinor, Currency::of($currencyCode))->amount(),
        ];
    }

    /**
     * @param mixed $amount a PayPal `{currency_code, value}` amount object
     */
    private function fromPayPalAmount(mixed $amount): int
    {
        if (!\is_array($amount) || !\is_string($amount['value'] ?? null) || !\is_string($amount['currency_code'] ?? null)) {
            throw new ProviderRequestFailed('PayPal returned an unexpected (non-object) amount field.');
        }

        return BrickMoney::of($amount['value'], strtoupper($amount['currency_code']))->getMinorAmount()->toInt();
    }

    private function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new ProviderRequestFailed('PayPal returned an unexpected (non-string) field.');
        }

        return $value;
    }

    private function fetchAccessToken(): string
    {
        try {
            $response = $this->http->request('POST', $this->baseUri . '/v1/oauth2/token', [
                'auth' => [$this->clientId, $this->clientSecret],
                'form_params' => ['grant_type' => 'client_credentials'],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        $body = $this->decodeJson((string) $response->getBody());
        if ($response->getStatusCode() >= 400) {
            $this->throwForStatus($response->getStatusCode(), $body);
        }

        $token = $body['access_token'] ?? null;
        if (!\is_string($token) || $token === '') {
            throw new ProviderRequestFailed('PayPal did not return an access token.');
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<array-key, mixed>
     */
    private function request(string $method, string $path, array $json = []): array
    {
        $token = $this->fetchAccessToken();

        $options = [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ],
            'http_errors' => false,
        ];
        if ($json !== []) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, $this->baseUri . $path, $options);
        } catch (GuzzleException $e) {
            throw new ProviderRequestFailed($e->getMessage(), previous: $e);
        }

        $body = $this->decodeJson((string) $response->getBody());
        if ($response->getStatusCode() >= 400) {
            $this->throwForStatus($response->getStatusCode(), $body);
        }

        return $body;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJson(string $contents): array
    {
        if ($contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function throwForStatus(int $status, array $body): never
    {
        $message = \is_string($body['message'] ?? null) ? $body['message'] : "PayPal request failed with status {$status}.";

        if ($status === 401 || $status === 403) {
            throw new ProviderAuthenticationFailed($message);
        }

        throw new ProviderRequestFailed($message);
    }
}
