<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use Gomrok\Http\Api\WebhooksReceiveAction;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ParsedWebhookEvent;
use Gomrok\Modules\Providers\Application\Adapter\ProviderWebhookVerificationFailed;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Webhooks\Application\IngestWebhookEvent\IngestWebhookEventHandler;
use Gomrok\Modules\Webhooks\Application\ProcessWebhookEvent\ProcessWebhookEventHandler;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Shared\Infrastructure\Persistence\NullErrorLogWriter;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentDirectory;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryProviderTransactionRepository;
use Gomrok\Tests\Support\InMemoryWebhookEventRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubProviderAccountCredentials;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class WebhooksReceiveActionTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;
    private const CHECKOUT_ATTEMPT = 100;
    private const TOKEN = 'whk_test_token';

    private FakePaymentProviderPort $adapter;
    private WebhooksReceiveAction $action;
    private InMemoryPaymentRepository $payments;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $events = new InMemoryWebhookEventRepository();
        $gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->payments = new InMemoryPaymentRepository();

        $this->adapter = new FakePaymentProviderPort();
        $adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter);

        $accounts = (new StubProviderAccountDirectory())
            ->add(self::PROVIDER_ACCOUNT, self::CLIENT, 'stripe-main', 'stripe')
            ->withWebhookToken(self::PROVIDER_ACCOUNT, self::TOKEN);
        $credentials = (new StubProviderAccountCredentials())->withEndpointSigningSecret(self::PROVIDER_ACCOUNT, 'webhook', 'whsec_test');

        $recordTransaction = new RecordProviderTransactionHandler(
            $this->payments,
            new InMemoryPaymentAttemptRepository(),
            new InMemoryProviderTransactionRepository(),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );
        $processor = new ProcessWebhookEventHandler(
            $events,
            $gatewayReferences,
            new InMemoryPaymentDirectory($this->payments),
            $adapterFactory,
            $recordTransaction,
            new NullErrorLogWriter(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );
        $handler = new IngestWebhookEventHandler($accounts, $credentials, $adapterFactory, $events, $processor, new FrozenClock('2026-09-14T12:00:00+00:00'));

        $this->action = new WebhooksReceiveAction($handler, new JsonResponder());

        $payment = Payment::create(self::CLIENT, self::CHECKOUT_ATTEMPT, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $this->now);
        $payment->transitionTo(PaymentStatus::Pending, $this->now);
        $this->payments->save($payment);
        $paymentId = $payment->id();
        \assert($paymentId !== null);
        $gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::PaymentIntent, 'pi_1', $paymentId, $this->now));
    }

    #[Test]
    public function aValidWebhookReturns200AndUpdatesThePayment(): void
    {
        $this->adapter->webhookParseResult(new ParsedWebhookEvent('evt_1', 'payment_intent.succeeded', 'pi_1', 'paid', ['id' => 'evt_1']));

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/webhooks/stripe/' . self::TOKEN)
            ->withHeader('Stripe-Signature', 't=1,v1=abc')
            ->withBody((new StreamFactory())->createStream('{"id":"evt_1"}'));

        $response = $this->action->__invoke($request, (new ResponseFactory())->createResponse(), ['provider' => 'stripe', 'token' => self::TOKEN]);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{outcome: string} $body */
        self::assertSame('processed', $body['outcome']);
    }

    #[Test]
    public function anInvalidSignatureReturns401(): void
    {
        $this->adapter->throwOnParseWebhook(new ProviderWebhookVerificationFailed('bad signature'));

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/webhooks/stripe/' . self::TOKEN)
            ->withBody((new StreamFactory())->createStream('{"id":"evt_bad"}'));

        $response = $this->action->__invoke($request, (new ResponseFactory())->createResponse(), ['provider' => 'stripe', 'token' => self::TOKEN]);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function anUnknownTokenReturns404(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/webhooks/stripe/whk_wrong')
            ->withBody((new StreamFactory())->createStream('{}'));

        $response = $this->action->__invoke($request, (new ResponseFactory())->createResponse(), ['provider' => 'stripe', 'token' => 'whk_wrong']);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function aProcessingFailureStillReturns200(): void
    {
        $this->adapter->webhookParseResult(new ParsedWebhookEvent('evt_2', 'payment_intent.succeeded', 'pi_unknown', 'paid', ['id' => 'evt_2']));

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/webhooks/stripe/' . self::TOKEN)
            ->withBody((new StreamFactory())->createStream('{"id":"evt_2"}'));

        $response = $this->action->__invoke($request, (new ResponseFactory())->createResponse(), ['provider' => 'stripe', 'token' => self::TOKEN]);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{outcome: string} $body */
        self::assertSame('retry_pending', $body['outcome']);
    }
}
