<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Infrastructure\Adapter\Stripe;

use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAuthenticationFailed;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRequestFailed;
use Gomrok\Modules\Providers\Application\Adapter\RawWebhook;
use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Infrastructure\Adapter\Stripe\StripeAdapter;
use Gomrok\Tests\Support\FakeStripeHttpClient;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/**
 * Exercises the real Stripe PHP SDK's request-building and response-parsing
 * (including its own exception mapping — a real 401 body genuinely produces a
 * real `AuthenticationException`) through a fake transport
 * ({@see FakeStripeHttpClient}), swapped in via `ApiRequestor::setHttpClient()`
 * — no real network call, no real credentials, fully deterministic. This is
 * the closest thing to "integration tests against Stripe test mode" runnable
 * without a real Stripe account; `tests/Integration/StripeAdapterLiveTest.php`
 * covers the genuine article, self-skipping without real credentials.
 */
final class StripeAdapterTest extends TestCase
{
    private FakeStripeHttpClient $http;
    private StripeAdapter $adapter;

    protected function setUp(): void
    {
        $this->http = new FakeStripeHttpClient();
        ApiRequestor::setHttpClient($this->http);

        $client = new StripeClient('sk_test_fake');
        $this->adapter = new StripeAdapter($client, InMemoryProviderTypeDeclarations::withKnownProviders());
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient());
    }

    #[Test]
    public function createPaymentReturnsARedirectUrlFromARealCheckoutSessionResponse(): void
    {
        $this->http->queue(200, [
            'id' => 'cs_test_123',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            'status' => 'open',
            'payment_status' => 'unpaid',
        ]);

        $result = $this->adapter->createPayment(new CreatePaymentCommand(
            attemptReference: 'order-1',
            amountMinor: 2900,
            currencyCode: 'EUR',
            description: 'Pro package',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        ));

        self::assertSame('cs_test_123', $result->providerReference);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_123', $result->redirectUrl);
        self::assertSame('open', $result->rawStatus);

        self::assertCount(1, $this->http->requests);
        $request = $this->http->requests[0];
        self::assertSame('post', $request['method']);
        self::assertStringContainsString('checkout/sessions', $request['url']);

        $params = $request['params'];
        self::assertSame('order-1', $params['client_reference_id'] ?? null);
        $lineItems = $params['line_items'] ?? null;
        self::assertIsArray($lineItems);
        $firstLineItem = $lineItems[0] ?? null;
        self::assertIsArray($firstLineItem);
        $priceData = $firstLineItem['price_data'] ?? null;
        self::assertIsArray($priceData);
        self::assertSame(2900, $priceData['unit_amount'] ?? null);
        self::assertSame('eur', $priceData['currency'] ?? null);
    }

    #[Test]
    public function a401ResponseIsMappedToProviderAuthenticationFailed(): void
    {
        $this->http->queue(401, [
            'error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided'],
        ]);

        $this->expectException(ProviderAuthenticationFailed::class);

        $this->adapter->createPayment(new CreatePaymentCommand(
            attemptReference: 'order-2',
            amountMinor: 2900,
            currencyCode: 'EUR',
            description: 'Pro package',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        ));
    }

    #[Test]
    public function aGenericErrorResponseIsMappedToProviderRequestFailed(): void
    {
        $this->http->queue(402, [
            'error' => ['type' => 'card_error', 'message' => 'Your card was declined.'],
        ]);

        $this->expectException(ProviderRequestFailed::class);

        $this->adapter->createPayment(new CreatePaymentCommand(
            attemptReference: 'order-3',
            amountMinor: 2900,
            currencyCode: 'EUR',
            description: 'Pro package',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        ));
    }

    #[Test]
    public function parseWebhookVerifiesARealSignatureAndParsesTheEvent(): void
    {
        $secret = 'whsec_test_secret';
        $payload = json_encode([
            'id' => 'evt_test_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_123', 'object' => 'checkout.session', 'status' => 'complete']],
        ]);
        self::assertIsString($payload);
        $header = $this->stripeSignatureHeader($payload, $secret);

        $webhook = new RawWebhook($payload, $header, $secret);

        self::assertTrue($this->adapter->verifyWebhookSignature($webhook));

        $event = $this->adapter->parseWebhook($webhook);
        self::assertSame('evt_test_1', $event->eventId);
        self::assertSame('checkout.session.completed', $event->eventType);
        self::assertSame('cs_test_123', $event->providerReference);
        self::assertSame('complete', $event->rawStatus);
    }

    #[Test]
    public function parseWebhookRejectsATamperedSignature(): void
    {
        $payload = '{"id":"evt_test_2","object":"event","type":"checkout.session.completed","data":{"object":{}}}';
        $header = $this->stripeSignatureHeader($payload, 'whsec_the_real_secret');

        $webhook = new RawWebhook($payload, $header, 'whsec_a_different_secret');

        self::assertFalse($this->adapter->verifyWebhookSignature($webhook));
        $this->expectException(\Gomrok\Modules\Providers\Application\Adapter\ProviderWebhookVerificationFailed::class);
        $this->adapter->parseWebhook($webhook);
    }

    #[Test]
    public function getCapabilitiesDelegatesToTheSeededDeclaration(): void
    {
        $capabilities = $this->adapter->getCapabilities();

        self::assertTrue($capabilities->has(Capability::HostedCheckout));
        self::assertTrue($capabilities->has(Capability::Refund));
        self::assertTrue($capabilities->has(Capability::BillingPortal));
    }

    private function stripeSignatureHeader(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }
}
