<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Http;

use Gomrok\Shared\Application\Idempotency\IdempotencyStatus;
use Gomrok\Shared\Http\IdempotencyContext;
use Gomrok\Shared\Http\IdempotencyMiddleware;
use Gomrok\Shared\Http\IdempotentReplayResolver;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryIdempotencyStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class IdempotencyMiddlewareTest extends TestCase
{
    private const CLIENT_ID = 7;
    private const KEY = 'abc-123';

    private InMemoryIdempotencyStore $store;
    private FrozenClock $clock;
    private IdempotencyContext $context;

    protected function setUp(): void
    {
        $this->store = new InMemoryIdempotencyStore();
        $this->clock = new FrozenClock('2026-09-08T12:00:00+00:00');
        $this->context = new IdempotencyContext();
    }

    #[Test]
    public function passesThroughWhenNoKeyIsSupplied(): void
    {
        $handler = $this->handler(fn (): ResponseInterface => $this->response(201));

        $response = $this->middleware()->process($this->request('POST', '/payments'), $handler);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($handler->wasCalled);
    }

    #[Test]
    public function passesThroughForNonWriteMethodEvenWithKey(): void
    {
        $handler = $this->handler(fn (): ResponseInterface => $this->response(200));

        $response = $this->middleware()->process(
            $this->request('GET', '/payments/1')->withHeader(IdempotencyMiddleware::HEADER, self::KEY)
                ->withAttribute(IdempotencyMiddleware::CLIENT_ID_ATTRIBUTE, self::CLIENT_ID),
            $handler,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->wasCalled);
    }

    #[Test]
    public function passesThroughWhenNoClientIsResolved(): void
    {
        $handler = $this->handler(fn (): ResponseInterface => $this->response(201));

        $response = $this->middleware()->process(
            $this->request('POST', '/payments')->withHeader(IdempotencyMiddleware::HEADER, self::KEY),
            $handler,
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($handler->wasCalled);
    }

    #[Test]
    public function malformedKeyIsRejected(): void
    {
        $request = $this->request('POST', '/payments')
            ->withHeader(IdempotencyMiddleware::HEADER, 'has spaces and tabs')
            ->withAttribute(IdempotencyMiddleware::CLIENT_ID_ATTRIBUTE, self::CLIENT_ID);

        $response = $this->middleware()->process($request, $this->handler());

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function freshKeyRunsTheHandlerAndIsMarkedDone(): void
    {
        $handler = $this->handler(function (): ResponseInterface {
            $this->context->setTarget('payment', 99);

            return $this->response(201);
        });

        $response = $this->middleware()->process($this->write(), $handler);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($handler->wasCalled);

        $replay = $this->store->claim(self::CLIENT_ID, self::KEY, 'x', $this->clock->now(), $this->clock->now()->modify('+1 hour'));
        self::assertNotNull($replay);
        self::assertSame(IdempotencyStatus::Done, $replay->status);
        self::assertSame('payment', $replay->targetType);
        self::assertSame(99, $replay->targetId);
        self::assertSame(201, $replay->responseStatus);
    }

    #[Test]
    public function inFlightKeyGetsConflict(): void
    {
        $fingerprint = $this->fingerprint('POST', '/payments');
        $this->store->claim(self::CLIENT_ID, self::KEY, $fingerprint, $this->clock->now(), $this->clock->now()->modify('+1 day'));

        $handler = $this->handler();
        $response = $this->middleware()->process($this->write(), $handler);

        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($handler->wasCalled);
    }

    #[Test]
    public function sameKeyWithADifferentRequestGets422(): void
    {
        $this->store->claim(self::CLIENT_ID, self::KEY, 'fingerprint-of-some-other-request', $this->clock->now(), $this->clock->now()->modify('+1 day'));

        $response = $this->middleware()->process($this->write(), $this->handler());

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function completedKeyReplaysAPointerBody(): void
    {
        $fingerprint = $this->fingerprint('POST', '/payments');
        $this->store->claim(self::CLIENT_ID, self::KEY, $fingerprint, $this->clock->now(), $this->clock->now()->modify('+1 day'));
        $this->store->markCompleted(self::CLIENT_ID, self::KEY, 'payment', 42, 201, $this->clock->now());

        $handler = $this->handler();
        $response = $this->middleware()->process($this->write(), $handler);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('true', $response->getHeaderLine('Idempotent-Replayed'));
        self::assertFalse($handler->wasCalled);

        /** @var array{idempotent_replay: bool, target_type: string, target_id: int} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['idempotent_replay']);
        self::assertSame('payment', $body['target_type']);
        self::assertSame(42, $body['target_id']);
    }

    #[Test]
    public function completedKeyReplaysResolvedEntityState(): void
    {
        $fingerprint = $this->fingerprint('POST', '/payments');
        $this->store->claim(self::CLIENT_ID, self::KEY, $fingerprint, $this->clock->now(), $this->clock->now()->modify('+1 day'));
        $this->store->markCompleted(self::CLIENT_ID, self::KEY, 'payment', 42, 201, $this->clock->now());

        $resolver = new class () implements IdempotentReplayResolver {
            public function resolve(string $targetType, int $targetId): array
            {
                return ['id' => $targetId, 'type' => $targetType, 'status' => 'paid'];
            }
        };

        $response = $this->middleware($resolver)->process($this->write(), $this->handler());

        self::assertSame(201, $response->getStatusCode());

        /** @var array{id: int, status: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(42, $body['id']);
        self::assertSame('paid', $body['status']);
    }

    #[Test]
    public function handlerFailureMarksTheKeyFailedAndRethrows(): void
    {
        $handler = $this->handler(function (): ResponseInterface {
            throw new RuntimeException('boom');
        });

        try {
            $this->middleware()->process($this->write(), $handler);
            self::fail('exception not propagated');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        // Failed → a later attempt reclaims the key (claim returns null).
        $reclaim = $this->store->claim(self::CLIENT_ID, self::KEY, 'x', $this->clock->now(), $this->clock->now()->modify('+1 day'));
        self::assertNull($reclaim);
    }

    #[Test]
    public function nonSuccessResponseMarksTheKeyFailed(): void
    {
        $handler = $this->handler(fn (): ResponseInterface => $this->response(400));

        $this->middleware()->process($this->write(), $handler);

        $reclaim = $this->store->claim(self::CLIENT_ID, self::KEY, 'x', $this->clock->now(), $this->clock->now()->modify('+1 day'));
        self::assertNull($reclaim);
    }

    #[Test]
    public function requiresAKeyOnWritesWhenConfiguredTo(): void
    {
        $handler = $this->handler();
        $response = $this->middleware(requireKeyOnWrites: true)->process(
            $this->request('POST', '/payments')->withAttribute(IdempotencyMiddleware::CLIENT_ID_ATTRIBUTE, self::CLIENT_ID),
            $handler,
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($handler->wasCalled);

        /** @var array{code: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('idempotency_key_required', $body['code']);
    }

    private function middleware(?IdempotentReplayResolver $resolver = null, bool $requireKeyOnWrites = false): IdempotencyMiddleware
    {
        return new IdempotencyMiddleware(
            $this->store,
            $this->clock,
            new ResponseFactory(),
            new JsonResponder(),
            $this->context,
            $resolver,
            $requireKeyOnWrites,
        );
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path);
    }

    private function write(): ServerRequestInterface
    {
        return $this->request('POST', '/payments')
            ->withHeader(IdempotencyMiddleware::HEADER, self::KEY)
            ->withAttribute(IdempotencyMiddleware::CLIENT_ID_ATTRIBUTE, self::CLIENT_ID);
    }

    private function fingerprint(string $method, string $path, string $body = ''): string
    {
        return hash('sha256', $method . "\n" . $path . "\n" . $body);
    }

    /**
     * @param (callable(): ResponseInterface)|null $behaviour
     *
     * @return RequestHandlerInterface&object{wasCalled: bool}
     */
    private function handler(?callable $behaviour = null): RequestHandlerInterface
    {
        return new class ($behaviour) implements RequestHandlerInterface {
            public bool $wasCalled = false;

            /** @var (callable(): ResponseInterface)|null */
            private $behaviour;

            public function __construct(?callable $behaviour)
            {
                $this->behaviour = $behaviour;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->wasCalled = true;

                if ($this->behaviour !== null) {
                    return ($this->behaviour)();
                }

                return (new ResponseFactory())->createResponse(200);
            }
        };
    }

    private function response(int $status): ResponseInterface
    {
        return (new ResponseFactory())->createResponse($status);
    }
}
