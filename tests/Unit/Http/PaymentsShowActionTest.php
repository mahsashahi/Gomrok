<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use Gomrok\Http\Api\PaymentsShowAction;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptDirectory;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentDirectory;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PaymentsShowActionTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    #[Test]
    public function reportsTheCheckoutAttemptsOwnStateBeforeAPaymentExists(): void
    {
        $attempts = new InMemoryCheckoutAttemptRepository();
        $now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $now);
        $attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        $action = $this->action($attempts, new InMemoryPaymentRepository());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', "/api/v1/payments/{$attemptId}"),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $attemptId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{payment_id: mixed, status: string} $body */
        self::assertNull($body['payment_id']);
        self::assertSame('started', $body['status']);
    }

    #[Test]
    public function anUnknownIdIs404(): void
    {
        $action = $this->action(new InMemoryCheckoutAttemptRepository(), new InMemoryPaymentRepository());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/payments/999'),
            (new ResponseFactory())->createResponse(),
            ['id' => '999'],
        );

        self::assertSame(404, $response->getStatusCode());
    }

    private function action(InMemoryCheckoutAttemptRepository $attempts, InMemoryPaymentRepository $payments): PaymentsShowAction
    {
        $context = new ClientContext();
        $context->set(new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'test'));

        return new PaymentsShowAction($context, new InMemoryCheckoutAttemptDirectory($attempts), new InMemoryPaymentDirectory($payments), new JsonResponder());
    }
}
