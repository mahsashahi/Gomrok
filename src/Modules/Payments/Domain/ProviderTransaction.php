<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

use DateTimeImmutable;

/**
 * One raw provider call/response under a {@see PaymentAttempt} (Phase 20 Q3)
 * — an immutable event log entry, never updated after it's written. `kind` is
 * a generic, provider-agnostic operation label (`authorize` / `capture` /
 * `refund` / `void` / `status_check` / …). `providerStatusRaw` is the
 * **unmapped** provider status string — stored safely, never leaked into core
 * business logic (each Phase 21+ adapter owns the mapping into
 * {@see PaymentStatus}).
 */
final readonly class ProviderTransaction
{
    /**
     * @param array<array-key, mixed>|null $requestPayload
     * @param array<array-key, mixed>|null $responsePayload
     */
    public function __construct(
        public ?int $id,
        public int $paymentAttemptId,
        public string $kind,
        public ?array $requestPayload,
        public ?array $responsePayload,
        public string $providerStatusRaw,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<array-key, mixed>|null $requestPayload
     * @param array<array-key, mixed>|null $responsePayload
     */
    public static function record(int $paymentAttemptId, string $kind, ?array $requestPayload, ?array $responsePayload, string $providerStatusRaw, DateTimeImmutable $now): self
    {
        return new self(null, $paymentAttemptId, trim($kind), $requestPayload, $responsePayload, $providerStatusRaw, $now);
    }
}
