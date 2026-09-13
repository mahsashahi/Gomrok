<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Infrastructure\Adapter\Mollie;

use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand;
use Gomrok\Modules\Providers\Application\Adapter\CreateSubscriptionCommand;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAuthenticationFailed;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRequestFailed;
use Gomrok\Modules\Providers\Application\Adapter\ProviderWebhookVerificationFailed;
use Gomrok\Modules\Providers\Application\Adapter\RawWebhook;
use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Infrastructure\Adapter\Mollie\MollieAdapter;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\MollieApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real Mollie PHP SDK's request-building and response-parsing
 * through the SDK's own official test double (`MollieApiClient::fake()` /
 * `MockMollieClient` — swaps only the HTTP transport), the same testing
 * philosophy as `StripeAdapterTest`'s `FakeStripeHttpClient`: no real network
 * call, no real credentials, but the real SDK's request objects, response
 * hydration, and exception mapping all run for real.
 * `tests/Integration/MollieAdapterLiveTest.php` covers the genuine article.
 */
final class MollieAdapterTest extends TestCase
{
    #[Test]
    public function createPaymentReturnsARedirectUrlFromARealCheckoutResponse(): void
    {
        $client = MollieApiClient::fake([
            CreatePaymentRequest::class => MockResponse::ok([
                'id' => 'tr_test_123',
                'status' => 'open',
                'amount' => ['currency' => 'EUR', 'value' => '29.00'],
                '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/tr_test_123']],
            ]),
        ]);
        $adapter = new MollieAdapter($client, InMemoryProviderTypeDeclarations::withKnownProviders());

        $result = $adapter->createPayment(new CreatePaymentCommand(
            attemptReference: 'order-1',
            amountMinor: 2900,
            currencyCode: 'eur',
            description: 'Pro package',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
            paymentMethod: PaymentMethod::Ideal,
        ));

        self::assertSame('tr_test_123', $result->providerReference);
        self::assertSame('https://www.mollie.com/checkout/select-method/tr_test_123', $result->redirectUrl);
        self::assertSame('open', $result->rawStatus);

        $client->assertSent(function (PendingRequest $pendingRequest): bool {
            $request = $pendingRequest->getRequest();
            self::assertInstanceOf(CreatePaymentRequest::class, $request);
            $amount = (array) $request->payload()->get('amount');

            return ($amount['value'] ?? null) === '29.00'
                && ($amount['currency'] ?? null) === 'EUR'
                && $request->payload()->get('method') === 'ideal'
                && $request->payload()->get('redirectUrl') === 'https://example.com/success'
                && $request->payload()->get('cancelUrl') === 'https://example.com/cancel';
        });
    }

    #[Test]
    public function a401ResponseIsMappedToProviderAuthenticationFailed(): void
    {
        $client = MollieApiClient::fake([
            CreatePaymentRequest::class => MockResponse::error(401, 'Unauthorized', 'Missing authentication'),
        ]);
        $adapter = new MollieAdapter($client, InMemoryProviderTypeDeclarations::withKnownProviders());

        $this->expectException(ProviderAuthenticationFailed::class);

        $adapter->createPayment(new CreatePaymentCommand(
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
        $client = MollieApiClient::fake([
            CreatePaymentRequest::class => MockResponse::unprocessableEntity('The amount is invalid'),
        ]);
        $adapter = new MollieAdapter($client, InMemoryProviderTypeDeclarations::withKnownProviders());

        $this->expectException(ProviderRequestFailed::class);

        $adapter->createPayment(new CreatePaymentCommand(
            attemptReference: 'order-3',
            amountMinor: 2900,
            currencyCode: 'EUR',
            description: 'Pro package',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        ));
    }

    #[Test]
    public function getPaymentStatusMapsARealPaymentResponse(): void
    {
        $client = MollieApiClient::fake([
            GetPaymentRequest::class => MockResponse::ok([
                'id' => 'tr_test_456',
                'status' => 'paid',
                'amount' => ['currency' => 'EUR', 'value' => '29.00'],
            ]),
        ]);
        $adapter = new MollieAdapter($client, InMemoryProviderTypeDeclarations::withKnownProviders());

        $status = $adapter->getPaymentStatus('tr_test_456');

        self::assertSame('tr_test_456', $status->providerReference);
        self::assertSame('paid', $status->rawStatus);
        self::assertSame(PaymentStatus::Paid, $status->mappedStatus);
    }

    #[Test]
    public function verifyWebhookSignatureReFetchesTheNamedPaymentAndSucceedsWhenItExists(): void
    {
        $client = MollieApiClient::fake([
            GetPaymentRequest::class => MockResponse::ok(['id' => 'tr_test_789', 'status' => 'paid', 'amount' => ['currency' => 'EUR', 'value' => '10.00']]),
        ]);
        $adapter = new MollieAdapter($client, InMemoryProviderTypeDeclarations::withKnownProviders());

        $webhook = new RawWebhook('id=tr_test_789', '', '');

        self::assertTrue($adapter->verifyWebhookSignature($webhook));
    }

    #[Test]
    public function verifyWebhookSignatureFailsWhenThePaymentCannotBeFetched(): void
    {
        $client = MollieApiClient::fake([
            GetPaymentRequest::class => MockResponse::notFound(),
        ]);
        $adapter = new MollieAdapter($client, InMemoryProviderTypeDeclarations::withKnownProviders());

        self::assertFalse($adapter->verifyWebhookSignature(new RawWebhook('id=tr_does_not_exist', '', '')));
    }

    #[Test]
    public function parseWebhookReFetchesAndReturnsTheParsedEvent(): void
    {
        $client = MollieApiClient::fake([
            GetPaymentRequest::class => MockResponse::ok(['id' => 'tr_test_789', 'status' => 'paid', 'amount' => ['currency' => 'EUR', 'value' => '10.00']]),
        ]);
        $adapter = new MollieAdapter($client, InMemoryProviderTypeDeclarations::withKnownProviders());

        $event = $adapter->parseWebhook(new RawWebhook('id=tr_test_789', '', ''));

        self::assertSame('tr_test_789', $event->eventId);
        self::assertSame('payment.updated', $event->eventType);
        self::assertSame('tr_test_789', $event->providerReference);
        self::assertSame('paid', $event->rawStatus);
    }

    #[Test]
    public function parseWebhookRejectsAPayloadWithNoId(): void
    {
        $adapter = new MollieAdapter(MollieApiClient::fake([]), InMemoryProviderTypeDeclarations::withKnownProviders());

        $this->expectException(ProviderWebhookVerificationFailed::class);
        $adapter->parseWebhook(new RawWebhook('not-a-valid-body', '', ''));
    }

    #[Test]
    public function refundPaymentIssuesAFullRefundWhenNoAmountIsGiven(): void
    {
        $client = MollieApiClient::fake([
            GetPaymentRequest::class => MockResponse::ok(['id' => 'tr_test_999', 'status' => 'paid', 'amount' => ['currency' => 'EUR', 'value' => '29.00']]),
            CreatePaymentRefundRequest::class => MockResponse::ok(['id' => 're_test_1', 'amount' => ['currency' => 'EUR', 'value' => '29.00'], 'status' => 'pending']),
        ]);
        $adapter = new MollieAdapter($client, InMemoryProviderTypeDeclarations::withKnownProviders());

        $refund = $adapter->refundPayment('tr_test_999');

        self::assertSame('re_test_1', $refund->providerReference);
        self::assertSame(2900, $refund->amountMinor);
        self::assertSame('pending', $refund->rawStatus);
    }

    #[Test]
    public function createSubscriptionCreatesACustomerAndAFirstPayment(): void
    {
        $client = MollieApiClient::fake([
            CreateCustomerRequest::class => MockResponse::ok(['id' => 'cst_test_1']),
            CreatePaymentRequest::class => MockResponse::ok([
                'id' => 'tr_test_first_1',
                'status' => 'open',
                'sequenceType' => 'first',
                'amount' => ['currency' => 'EUR', 'value' => '9.00'],
                '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/tr_test_first_1']],
            ]),
        ]);
        $adapter = new MollieAdapter($client, InMemoryProviderTypeDeclarations::withKnownProviders());

        $result = $adapter->createSubscription(new CreateSubscriptionCommand(
            attemptReference: 'sub-order-1',
            amountMinor: 900,
            currencyCode: 'EUR',
            description: 'Monthly plan',
            interval: SubscriptionInterval::Monthly,
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
            customerEmail: 'user@example.com',
        ));

        self::assertSame('tr_test_first_1', $result->providerReference);
        self::assertSame('https://www.mollie.com/checkout/select-method/tr_test_first_1', $result->redirectUrl);

        $client->assertSent(CreateCustomerRequest::class);
        $client->assertSent(function (PendingRequest $pendingRequest): bool {
            $request = $pendingRequest->getRequest();

            return $request instanceof CreatePaymentRequest
                && $request->payload()->get('sequenceType') === 'first'
                && $request->payload()->get('customerId') === 'cst_test_1';
        });
    }

    #[Test]
    public function cancelSubscriptionCancelsTheUnderlyingFirstPayment(): void
    {
        $client = MollieApiClient::fake([
            CancelPaymentRequest::class => MockResponse::ok(['id' => 'tr_test_first_1', 'status' => 'canceled']),
        ]);
        $adapter = new MollieAdapter($client, InMemoryProviderTypeDeclarations::withKnownProviders());

        $adapter->cancelSubscription('tr_test_first_1');

        $client->assertSent(CancelPaymentRequest::class);
    }

    #[Test]
    public function getCapabilitiesDelegatesToTheSeededDeclarationAndExcludesCustomerPortal(): void
    {
        $adapter = new MollieAdapter(MollieApiClient::fake([]), InMemoryProviderTypeDeclarations::withKnownProviders());

        $capabilities = $adapter->getCapabilities();

        self::assertTrue($capabilities->has(Capability::HostedCheckout));
        self::assertTrue($capabilities->has(Capability::Refund));
        self::assertTrue($capabilities->has(Capability::SubscriptionCancel));
        self::assertTrue($capabilities->has(Capability::ManualStatusPolling));
        self::assertFalse($capabilities->has(Capability::CustomerPortal));
    }
}
