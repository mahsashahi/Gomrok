<?php

declare(strict_types=1);

namespace Gomrok\Modules\Webhooks\Domain;

use DateTimeImmutable;

/**
 * One inbound provider webhook, stored before any processing is attempted
 * (Phase 25). `eventId` / `eventType` / `rawStatus` are null when the
 * signature failed to verify — the payload was never trusted enough to
 * parse. `rawPayload` is the exact request body received, never re-encoded,
 * so a later retry (or a security investigation) can replay it byte-for-byte.
 *
 * `id` is null until persisted.
 */
final class WebhookEvent
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly int $providerAccountId,
        private readonly string $providerTypeCode,
        private readonly ?string $eventId,
        private readonly ?string $eventType,
        private readonly ?string $rawStatus,
        private readonly ?string $providerReference,
        private readonly string $rawPayload,
        private readonly array $headers,
        private WebhookEventStatus $status,
        private int $attemptCount,
        private ?string $errorCode,
        private ?string $errorMessage,
        private ?int $paymentId,
        private ?DateTimeImmutable $processedAt,
        private ?DateTimeImmutable $lastAttemptedAt,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
        private readonly ?string $subscriptionReference = null,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function receive(
        int $clientId,
        int $providerAccountId,
        string $providerTypeCode,
        ?string $eventId,
        ?string $eventType,
        ?string $rawStatus,
        ?string $providerReference,
        string $rawPayload,
        array $headers,
        DateTimeImmutable $now,
        ?string $subscriptionReference = null,
    ): self {
        return new self(
            null,
            $clientId,
            $providerAccountId,
            $providerTypeCode,
            $eventId,
            $eventType,
            $rawStatus,
            $providerReference,
            $rawPayload,
            $headers,
            WebhookEventStatus::Received,
            0,
            null,
            null,
            null,
            null,
            null,
            $now,
            null,
            $subscriptionReference,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public static function fromStorage(
        int $id,
        int $clientId,
        int $providerAccountId,
        string $providerTypeCode,
        ?string $eventId,
        ?string $eventType,
        ?string $rawStatus,
        ?string $providerReference,
        string $rawPayload,
        array $headers,
        WebhookEventStatus $status,
        int $attemptCount,
        ?string $errorCode,
        ?string $errorMessage,
        ?int $paymentId,
        ?DateTimeImmutable $processedAt,
        ?DateTimeImmutable $lastAttemptedAt,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
        ?string $subscriptionReference = null,
    ): self {
        return new self(
            $id,
            $clientId,
            $providerAccountId,
            $providerTypeCode,
            $eventId,
            $eventType,
            $rawStatus,
            $providerReference,
            $rawPayload,
            $headers,
            $status,
            $attemptCount,
            $errorCode,
            $errorMessage,
            $paymentId,
            $processedAt,
            $lastAttemptedAt,
            $createdAt,
            $updatedAt,
            $subscriptionReference,
        );
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function markProcessing(DateTimeImmutable $now): void
    {
        $this->status = WebhookEventStatus::Processing;
        $this->lastAttemptedAt = $now;
        $this->updatedAt = $now;
    }

    public function markProcessed(DateTimeImmutable $now, int $paymentId): void
    {
        $this->status = WebhookEventStatus::Processed;
        $this->paymentId = $paymentId;
        $this->processedAt = $now;
        $this->errorCode = null;
        $this->errorMessage = null;
        $this->updatedAt = $now;
    }

    /** The attempt failed but will be retried by the cron job. */
    public function markRetryPending(DateTimeImmutable $now, string $errorCode, string $errorMessage): void
    {
        $this->status = WebhookEventStatus::RetryPending;
        ++$this->attemptCount;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->updatedAt = $now;
    }

    /** Terminal: either max_attempts was exceeded or the failure is definitive (never retried again). */
    public function markFailed(DateTimeImmutable $now, string $errorCode, string $errorMessage): void
    {
        $this->status = WebhookEventStatus::Failed;
        ++$this->attemptCount;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->updatedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function providerAccountId(): int
    {
        return $this->providerAccountId;
    }

    public function providerTypeCode(): string
    {
        return $this->providerTypeCode;
    }

    public function eventId(): ?string
    {
        return $this->eventId;
    }

    public function eventType(): ?string
    {
        return $this->eventType;
    }

    public function rawStatus(): ?string
    {
        return $this->rawStatus;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function subscriptionReference(): ?string
    {
        return $this->subscriptionReference;
    }

    public function rawPayload(): string
    {
        return $this->rawPayload;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function payloadArray(): ?array
    {
        $decoded = json_decode($this->rawPayload, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function status(): WebhookEventStatus
    {
        return $this->status;
    }

    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function paymentId(): ?int
    {
        return $this->paymentId;
    }

    public function processedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function lastAttemptedAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
