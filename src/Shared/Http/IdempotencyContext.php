<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

/**
 * Per-request slot where a write handler records the entity it created, so
 * {@see IdempotencyMiddleware} can persist `target_type` / `target_id` against
 * the idempotency key and replay it later. One instance per request (the
 * container builds it fresh), mirroring {@see \Gomrok\Shared\Infrastructure\CorrelationId}.
 */
final class IdempotencyContext
{
    private ?string $targetType = null;
    private ?int $targetId = null;

    public function setTarget(string $targetType, int $targetId): void
    {
        $this->targetType = $targetType;
        $this->targetId = $targetId;
    }

    public function targetType(): ?string
    {
        return $this->targetType;
    }

    public function targetId(): ?int
    {
        return $this->targetId;
    }
}
