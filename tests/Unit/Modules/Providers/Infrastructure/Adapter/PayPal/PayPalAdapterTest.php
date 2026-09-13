<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Infrastructure\Adapter\PayPal;

use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAuthenticationFailed;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRequestFailed;
use Gomrok\Modules\Providers\Application\Adapter\ProviderWebhookVerificationFailed;
use Gomrok\Modules\Providers\Application\Adapter\RawWebhook;
use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Infrastructure\Adapter\PayPal\PayPalAdapter;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Exercises the real Guzzle HTTP stack (request building, JSON encoding,
 * response parsing) through a `MockHandler` — no real network, no real
 * credentials — the same testing philosophy as `StripeAdapterTest`/
 * `MollieAdapterTest`. `tests/Integration/PayPalAdapterLiveTest.php` covers
 * the genuine article. `PayPalAdapter` never caches its OAuth2 token, so
 * every adapter call needs a queued token response ahead of the queued
 * "real" response(s) it triggers — {@see withToken()} interleaves them.
 */
final class PayPalAdapterTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    private function buildAdapter(Response ...$responses): PayPalAdapter
    {
        $mock = new MockHandler($this->withToken(...$responses));
        $stack = HandlerStack::create($mock);
        $this->requests = [];
        $stack->push(function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->requests[] = $request;

                return $handler($request, $options);
            };
        });

        return new PayPalAdapter(
            new Client(['handler' => $stack]),
            'client-id',
            'client-secret',
            'https://api-m.sandbox.paypal.com',
            InMemoryProviderTypeDeclarations::withKnownProviders(),
        );
    }

    /**
     * @return list<Response>
     */
    private function withToken(Response ...$responses): array
    {
        $interleaved = [];
        foreach ($responses as $response) {
            $interleaved[] = new Response(200, [], $this->json(['access_token' => 'A.fake.token', 'expires_in' => 32400]));
            $interleaved[] = $response;
        }

        return $interleaved;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function json(array $data): string
    {
        $encoded = json_encode($data);

        return $encoded !== false ? $encoded : '{}';
    }

    private function requestAt(int $index): RequestInterface
    {
        return $this->requests[$index];
    }

    private function requestPath(int $index): string
    {
        return $this->requestAt($index)->getUri()->getPath();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function requestBody(int $index): array
    {
        $decoded = json_decode((string) $this->requestAt($index)->getBody(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Reads a string value out of a request body at a known key path,
     * narrowing PHPStan's view of the decoded-JSON `mixed` at each step via
     * PHPUnit assertions (phpstan-phpunit understands these as type guards) —
     * these are test fixtures whose shape is fully known upfront, not
     * defensive production parsing.
     */
    private function bodyString(int $index, int|string ...$path): string
    {
        $current = $this->requestBody($index);
        foreach ($path as $segment) {
            self::assertIsArray($current);
            $current = $current[$segment];
        }
        self::assertIsString($current);

        return $current;
    }

    #[Test]
    public function createPaymentCreatesACaptureIntentOrderAndReturnsTheApproveLink(): void
    {
        $adapter = $this->buildAdapter(new Response(201, [], $this->json([
            'id' => 'ORDER-1',
            'status' => 'CREATED',
            'links' => [['rel' => 'approve', 'href' => 'https://www.paypal.com/checkoutnow?token=ORDER-1']],
        ])));

        $result = $adapter->createPayment(new CreatePaymentCommand(
            attemptReference: 'order-1',
            amountMinor: 2900,
            currencyCode: 'eur',
            description: 'Pro package',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        ));

        self::assertSame('ORDER-1', $result->providerReference);
        self::assertSame('https://www.paypal.com/checkoutnow?token=ORDER-1', $result->redirectUrl);
        self::assertSame('CREATED', $result->rawStatus);

        self::assertSame('CAPTURE', $this->bodyString(1, 'intent'));
        self::assertSame('order-1', $this->bodyString(1, 'purchase_units', 0, 'reference_id'));
        self::assertSame('29.00', $this->bodyString(1, 'purchase_units', 0, 'amount', 'value'));
        self::assertSame('EUR', $this->bodyString(1, 'purchase_units', 0, 'amount', 'currency_code'));
        self::assertSame('https://example.com/success', $this->bodyString(1, 'application_context', 'return_url'));
        self::assertSame('https://example.com/cancel', $this->bodyString(1, 'application_context', 'cancel_url'));
    }

    #[Test]
    public function authorizePaymentCreatesAnAuthorizeIntentOrder(): void
    {
        $adapter = $this->buildAdapter(new Response(201, [], $this->json(['id' => 'ORDER-2', 'status' => 'CREATED', 'links' => []])));

        $adapter->authorizePayment(new CreatePaymentCommand('order-2', 1000, 'USD', 'x', 'https://a', 'https://b'));

        self::assertSame('AUTHORIZE', $this->bodyString(1, 'intent'));
    }

    #[Test]
    public function capturePaymentCapturesACaptureIntentOrderDirectly(): void
    {
        $getOrder = new Response(200, [], $this->json(['id' => 'ORDER-3', 'intent' => 'CAPTURE', 'status' => 'APPROVED']));
        $capture = new Response(201, [], $this->json(['id' => 'CAP-1', 'status' => 'COMPLETED']));
        $adapter = $this->buildAdapter($getOrder, $capture);

        $result = $adapter->capturePayment('ORDER-3');

        self::assertSame('ORDER-3', $result->providerReference);
        self::assertSame('COMPLETED', $result->rawStatus);
    }

    #[Test]
    public function capturePaymentAuthorizesThenCapturesAnAuthorizeIntentOrder(): void
    {
        $getOrder = new Response(200, [], $this->json(['id' => 'ORDER-4', 'intent' => 'AUTHORIZE', 'status' => 'APPROVED']));
        $authorize = new Response(201, [], $this->json([
            'purchase_units' => [['payments' => ['authorizations' => [['id' => 'AUTH-1', 'status' => 'CREATED']]]]],
        ]));
        $capture = new Response(201, [], $this->json(['id' => 'CAP-2', 'status' => 'COMPLETED']));
        $adapter = $this->buildAdapter($getOrder, $authorize, $capture);

        $result = $adapter->capturePayment('ORDER-4');

        self::assertSame('AUTH-1', $result->providerReference);
        self::assertSame('COMPLETED', $result->rawStatus);
        self::assertSame('/v2/checkout/orders/ORDER-4/authorize', $this->requestPath(3));
        self::assertSame('/v2/payments/authorizations/AUTH-1/capture', $this->requestPath(5));
    }

    #[Test]
    public function cancelPaymentVoidsAnExistingAuthorization(): void
    {
        $getOrder = new Response(200, [], $this->json([
            'id' => 'ORDER-5',
            'purchase_units' => [['payments' => ['authorizations' => [['id' => 'AUTH-2', 'status' => 'CREATED']]]]],
        ]));
        $void = new Response(204, [], '');
        $adapter = $this->buildAdapter($getOrder, $void);

        $adapter->cancelPayment('ORDER-5');

        self::assertSame('/v2/payments/authorizations/AUTH-2/void', $this->requestPath(3));
    }

    #[Test]
    public function cancelPaymentFailsWhenThereIsNothingToVoid(): void
    {
        $getOrder = new Response(200, [], $this->json(['id' => 'ORDER-6', 'status' => 'CREATED']));
        $adapter = $this->buildAdapter($getOrder);

        $this->expectException(ProviderRequestFailed::class);
        $adapter->cancelPayment('ORDER-6');
    }

    #[Test]
    public function getPaymentStatusSurfacesTheCaptureIdAsTheDeeperReference(): void
    {
        $getOrder = new Response(200, [], $this->json([
            'id' => 'ORDER-7',
            'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-3', 'status' => 'COMPLETED']]]]],
        ]));
        $adapter = $this->buildAdapter($getOrder);

        $status = $adapter->getPaymentStatus('ORDER-7');

        self::assertSame('ORDER-7', $status->providerReference);
        self::assertSame('COMPLETED', $status->rawStatus);
        self::assertSame(PaymentStatus::Paid, $status->mappedStatus);
        self::assertSame('CAP-3', $status->paymentIntentReference);
    }

    #[Test]
    public function refundPaymentIssuesAFullRefundWhenNoAmountIsGiven(): void
    {
        $refund = new Response(201, [], $this->json(['id' => 'REF-1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '29.00']]));
        $adapter = $this->buildAdapter($refund);

        $result = $adapter->refundPayment('CAP-4');

        self::assertSame('REF-1', $result->providerReference);
        self::assertSame(2900, $result->amountMinor);
        self::assertSame('COMPLETED', $result->rawStatus);
        self::assertSame('', (string) $this->requestAt(1)->getBody());
    }

    #[Test]
    public function refundPaymentIssuesAPartialRefundWhenAnAmountIsGiven(): void
    {
        $getCapture = new Response(200, [], $this->json(['id' => 'CAP-5', 'amount' => ['currency_code' => 'EUR', 'value' => '29.00']]));
        $refund = new Response(201, [], $this->json(['id' => 'REF-2', 'status' => 'PENDING', 'amount' => ['currency_code' => 'EUR', 'value' => '10.00']]));
        $adapter = $this->buildAdapter($getCapture, $refund);

        $result = $adapter->refundPayment('CAP-5', 1000);

        self::assertSame(1000, $result->amountMinor);
        self::assertSame('10.00', $this->bodyString(3, 'amount', 'value'));
        self::assertSame('EUR', $this->bodyString(3, 'amount', 'currency_code'));
    }

    #[Test]
    public function a401OnTheAccessTokenCallIsMappedToProviderAuthenticationFailed(): void
    {
        $mock = new MockHandler([new Response(401, [], $this->json(['error' => 'invalid_client']))]);
        $adapter = new PayPalAdapter(
            new Client(['handler' => HandlerStack::create($mock)]),
            'bad-id',
            'bad-secret',
            'https://api-m.sandbox.paypal.com',
            InMemoryProviderTypeDeclarations::withKnownProviders(),
        );

        $this->expectException(ProviderAuthenticationFailed::class);
        $adapter->createPayment(new CreatePaymentCommand('order-x', 100, 'EUR', 'x', 'https://a', 'https://b'));
    }

    #[Test]
    public function aGenericErrorResponseIsMappedToProviderRequestFailed(): void
    {
        $adapter = $this->buildAdapter(new Response(422, [], $this->json(['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The amount is invalid'])));

        $this->expectException(ProviderRequestFailed::class);
        $adapter->createPayment(new CreatePaymentCommand('order-y', 100, 'EUR', 'x', 'https://a', 'https://b'));
    }

    #[Test]
    public function verifyWebhookSignatureSucceedsWhenPayPalConfirmsIt(): void
    {
        $adapter = $this->buildAdapter(new Response(200, [], $this->json(['verification_status' => 'SUCCESS'])));

        $webhook = new RawWebhook(
            '{"id":"WH-1","event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"CAP-6","status":"COMPLETED"}}',
            '',
            'WH-webhook-id',
            [
                'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
                'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert',
                'PAYPAL-TRANSMISSION-ID' => 'tx-1',
                'PAYPAL-TRANSMISSION-SIG' => 'sig',
                'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z',
            ],
        );

        self::assertTrue($adapter->verifyWebhookSignature($webhook));
    }

    #[Test]
    public function parseWebhookRejectsAnUnverifiedSignature(): void
    {
        $adapter = $this->buildAdapter(new Response(200, [], $this->json(['verification_status' => 'FAILURE'])));

        $webhook = new RawWebhook('{"id":"WH-2","event_type":"x","resource":{}}', '', 'WH-webhook-id', []);

        $this->expectException(ProviderWebhookVerificationFailed::class);
        $adapter->parseWebhook($webhook);
    }

    #[Test]
    public function parseWebhookReturnsTheParsedEventWhenVerified(): void
    {
        $adapter = $this->buildAdapter(new Response(200, [], $this->json(['verification_status' => 'SUCCESS'])));

        $webhook = new RawWebhook(
            '{"id":"WH-3","event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"CAP-7","status":"COMPLETED"}}',
            '',
            'WH-webhook-id',
            [],
        );

        $event = $adapter->parseWebhook($webhook);

        self::assertSame('WH-3', $event->eventId);
        self::assertSame('PAYMENT.CAPTURE.COMPLETED', $event->eventType);
        self::assertSame('CAP-7', $event->providerReference);
        self::assertSame('COMPLETED', $event->rawStatus);
    }

    #[Test]
    public function getCapabilitiesDelegatesToTheSeededDeclaration(): void
    {
        $adapter = $this->buildAdapter();

        $capabilities = $adapter->getCapabilities();

        self::assertTrue($capabilities->has(Capability::Authorization));
        self::assertTrue($capabilities->has(Capability::Capture));
        self::assertTrue($capabilities->has(Capability::Refund));
        self::assertTrue($capabilities->has(Capability::SubscriptionCancel));
    }
}
