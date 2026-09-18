<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Domain;

use DateTimeImmutable;

/**
 * One outbound status-change callback Gomrok owes a client (Phase 28) —
 * server-to-server, never an in-app/UI notification. `endpointUrl` and
 * `payload` are snapshots taken at {@see enqueue()} time and never change
 * afterward, so every delivery attempt (including retries) sends the exact
 * same request to the exact same place a later URL change can't affect.
 *
 * `id` is null until persisted.
 */
final class ClientNotification
{
    private const RESPONSE_BODY_MAX_LENGTH = 4000;

    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly NotificationTargetType $targetType,
        private readonly int $targetId,
        private readonly string $purpose,
        private readonly string $statusValue,
        private readonly ?int $providerAccountId,
        private readonly string $endpointUrl,
        private readonly string $payload,
        private ClientNotificationStatus $status,
        private int $attemptCount,
        private ?DateTimeImmutable $nextAttemptAt,
        private ?DateTimeImmutable $lastAttemptedAt,
        private ?int $lastResponseStatus,
        private ?string $lastResponseBody,
        private ?string $lastError,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function enqueue(
        int $clientId,
        NotificationTargetType $targetType,
        int $targetId,
        string $purpose,
        string $statusValue,
        ?int $providerAccountId,
        string $endpointUrl,
        string $payload,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            $clientId,
            $targetType,
            $targetId,
            $purpose,
            $statusValue,
            $providerAccountId,
            $endpointUrl,
            $payload,
            ClientNotificationStatus::Pending,
            0,
            $now,
            null,
            null,
            null,
            null,
            $now,
            null,
        );
    }

    public static function fromStorage(
        int $id,
        int $clientId,
        NotificationTargetType $targetType,
        int $targetId,
        string $purpose,
        string $statusValue,
        ?int $providerAccountId,
        string $endpointUrl,
        string $payload,
        ClientNotificationStatus $status,
        int $attemptCount,
        ?DateTimeImmutable $nextAttemptAt,
        ?DateTimeImmutable $lastAttemptedAt,
        ?int $lastResponseStatus,
        ?string $lastResponseBody,
        ?string $lastError,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $clientId,
            $targetType,
            $targetId,
            $purpose,
            $statusValue,
            $providerAccountId,
            $endpointUrl,
            $payload,
            $status,
            $attemptCount,
            $nextAttemptAt,
            $lastAttemptedAt,
            $lastResponseStatus,
            $lastResponseBody,
            $lastError,
            $createdAt,
            $updatedAt,
        );
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    /** A delivery attempt succeeded (2xx). Terminal. */
    public function recordSuccess(DateTimeImmutable $now, int $responseStatus, ?string $responseBody): void
    {
        $this->status = ClientNotificationStatus::Sent;
        ++$this->attemptCount;
        $this->lastAttemptedAt = $now;
        $this->lastResponseStatus = $responseStatus;
        $this->lastResponseBody = $this->truncate($responseBody);
        $this->lastError = null;
        $this->nextAttemptAt = null;
        $this->updatedAt = $now;
    }

    /** A delivery attempt failed but more attempts remain — stays `pending`. */
    public function recordRetry(DateTimeImmutable $now, ?int $responseStatus, ?string $responseBody, ?string $error, DateTimeImmutable $nextAttemptAt): void
    {
        ++$this->attemptCount;
        $this->lastAttemptedAt = $now;
        $this->lastResponseStatus = $responseStatus;
        $this->lastResponseBody = $this->truncate($responseBody);
        $this->lastError = $error;
        $this->nextAttemptAt = $nextAttemptAt;
        $this->updatedAt = $now;
    }

    /** A delivery attempt failed and no attempts remain. Terminal until a manual retry. */
    public function recordDeadLetter(DateTimeImmutable $now, ?int $responseStatus, ?string $responseBody, ?string $error): void
    {
        $this->status = ClientNotificationStatus::DeadLettered;
        ++$this->attemptCount;
        $this->lastAttemptedAt = $now;
        $this->lastResponseStatus = $responseStatus;
        $this->lastResponseBody = $this->truncate($responseBody);
        $this->lastError = $error;
        $this->nextAttemptAt = null;
        $this->updatedAt = $now;
    }

    /**
     * Admin manual retry (`notifications.retry`) — resets the attempt count so
     * a `dead_lettered` row gets the full backoff sequence again rather than
     * immediately re-dead-lettering on its next failure; the historical
     * attempts are still visible via `updated_at`/audit logs, just not kept
     * on this row once an operator has decided to give it a fresh try.
     */
    public function retryFromDeadLetter(DateTimeImmutable $now): void
    {
        $this->status = ClientNotificationStatus::Pending;
        $this->attemptCount = 0;
        $this->nextAttemptAt = $now;
        $this->updatedAt = $now;
    }

    private function truncate(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        return \strlen($body) > self::RESPONSE_BODY_MAX_LENGTH
            ? substr($body, 0, self::RESPONSE_BODY_MAX_LENGTH) . '…(truncated)'
            : $body;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function targetType(): NotificationTargetType
    {
        return $this->targetType;
    }

    public function targetId(): int
    {
        return $this->targetId;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function statusValue(): string
    {
        return $this->statusValue;
    }

    public function providerAccountId(): ?int
    {
        return $this->providerAccountId;
    }

    public function endpointUrl(): string
    {
        return $this->endpointUrl;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function status(): ClientNotificationStatus
    {
        return $this->status;
    }

    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    public function nextAttemptAt(): ?DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function lastAttemptedAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptedAt;
    }

    public function lastResponseStatus(): ?int
    {
        return $this->lastResponseStatus;
    }

    public function lastResponseBody(): ?string
    {
        return $this->lastResponseBody;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
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
