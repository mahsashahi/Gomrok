<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

/**
 * Rehydrates the current representation of an entity an earlier idempotent
 * request created, so a replay returns fresh state (not a frozen snapshot).
 *
 * Phase 5 ships no implementation — modules that own replayable write endpoints
 * register one from Phase 7 onward. When absent, or when it returns `null`,
 * {@see IdempotencyMiddleware} replays a minimal pointer body instead.
 */
interface IdempotentReplayResolver
{
    /**
     * @return array<string, mixed>|null the entity's current representation, or
     *                                   `null` if this resolver cannot handle
     *                                   the given target type
     */
    public function resolve(string $targetType, int $targetId): ?array;
}
