<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use Gomrok\Shared\Application\Idempotency\IdempotencyStatus;
use Gomrok\Shared\Application\Idempotency\IdempotencyStore;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Makes a client write request idempotent when it carries an `Idempotency-Key`
 * header (decision: `PhaseResults/PhaseDecisions.md` Phase 5 Q1 — lock + entity mapping).
 *
 * - fresh key           → claim it `processing`, run the handler, then mark it
 *                         `done` (recording the created entity via
 *                         {@see IdempotencyContext}) or `failed` on a throw;
 * - key still `processing` → 409 (a concurrent retry);
 * - key `done`          → replay the referenced entity's current state via the
 *                         {@see IdempotentReplayResolver}, or a pointer body;
 * - key reused with a different request body → 422.
 *
 * Non-write methods and requests with no resolved client pass straight through.
 * A write with no `Idempotency-Key` passes through unless `requireKeyOnWrites`
 * is set (the `/api/v1` group sets it — Phase 7 Q5 — so a keyless write gets
 * `400 idempotency_key_required`). Registered per-route by the client-API layer,
 * not on the global stack.
 */
final readonly class IdempotencyMiddleware implements MiddlewareInterface
{
    public const HEADER = 'Idempotency-Key';
    public const CLIENT_ID_ATTRIBUTE = 'authClientId';

    /** How long a claimed key is honoured before it is treated as absent. */
    public const TTL_SECONDS = 86_400;

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private IdempotencyStore $store,
        private ClockInterface $clock,
        private ResponseFactoryInterface $responseFactory,
        private JsonResponder $responder,
        private IdempotencyContext $context,
        private ?IdempotentReplayResolver $replayResolver = null,
        private bool $requireKeyOnWrites = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->keyFrom($request);
        $clientId = $this->clientIdFrom($request);
        $isWrite = \in_array($request->getMethod(), self::WRITE_METHODS, true);

        if (!$isWrite || $clientId === null) {
            return $handler->handle($request);
        }

        if ($key === null) {
            if ($this->requireKeyOnWrites) {
                return $this->problem(400, 'idempotency_key_required', 'This request requires an Idempotency-Key header.');
            }

            return $handler->handle($request);
        }

        if (!$this->isWellFormed($key)) {
            return $this->problem(400, 'invalid_idempotency_key', 'The Idempotency-Key header is malformed.');
        }

        $now = $this->clock->now();
        $expiresAt = $now->modify('+' . self::TTL_SECONDS . ' seconds');
        $fingerprint = $this->fingerprint($request);

        $existing = $this->store->claim($clientId, $key, $fingerprint, $now, $expiresAt);

        if ($existing !== null) {
            if (!$existing->matchesFingerprint($fingerprint)) {
                return $this->problem(
                    422,
                    'idempotency_key_reuse',
                    'This Idempotency-Key was already used for a different request.',
                );
            }

            if ($existing->status === IdempotencyStatus::Processing) {
                return $this->problem(
                    409,
                    'idempotent_request_in_progress',
                    'A request with this Idempotency-Key is still being processed.',
                );
            }

            return $this->replay($existing->targetType, $existing->targetId, $existing->responseStatus ?? 200);
        }

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $this->store->markFailed($clientId, $key, $this->clock->now());

            throw $e;
        }

        if ($this->isSuccess($response)) {
            $this->store->markCompleted(
                $clientId,
                $key,
                $this->context->targetType(),
                $this->context->targetId(),
                $response->getStatusCode(),
                $this->clock->now(),
            );
        } else {
            $this->store->markFailed($clientId, $key, $this->clock->now());
        }

        return $response;
    }

    private function keyFrom(ServerRequestInterface $request): ?string
    {
        $value = $request->getHeaderLine(self::HEADER);

        return $value === '' ? null : $value;
    }

    private function clientIdFrom(ServerRequestInterface $request): ?int
    {
        $value = $request->getAttribute(self::CLIENT_ID_ATTRIBUTE);

        return \is_int($value) && $value > 0 ? $value : null;
    }

    private function isWellFormed(string $key): bool
    {
        return \strlen($key) <= 255 && preg_match('/^[\x21-\x7e]+$/', $key) === 1;
    }

    private function fingerprint(ServerRequestInterface $request): string
    {
        $body = (string) $request->getBody();
        $request->getBody()->rewind();

        return hash('sha256', $request->getMethod() . "\n" . $request->getUri()->getPath() . "\n" . $body);
    }

    private function isSuccess(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300;
    }

    private function replay(?string $targetType, ?int $targetId, int $status): ResponseInterface
    {
        $resolved = null;
        if ($this->replayResolver !== null && $targetType !== null && $targetId !== null) {
            $resolved = $this->replayResolver->resolve($targetType, $targetId);
        }

        $body = $resolved ?? [
            'idempotent_replay' => true,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ];

        return $this->responder->json($this->responseFactory->createResponse($status), $body, $status)
            ->withHeader('Idempotent-Replayed', 'true');
    }

    private function problem(int $status, string $code, string $message): ResponseInterface
    {
        return $this->responder->json($this->responseFactory->createResponse($status), [
            'type' => 'about:blank',
            'title' => $message,
            'status' => $status,
            'code' => $code,
        ], $status);
    }
}
