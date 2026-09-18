<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application;

final readonly class ClientNotificationSendOutcome
{
    private function __construct(
        public bool $success,
        public ?int $responseStatus,
        public ?string $responseBody,
        public ?string $errorMessage,
    ) {
    }

    public static function delivered(int $responseStatus, ?string $responseBody): self
    {
        return new self(true, $responseStatus, $responseBody, null);
    }

    /** A response came back, but not a 2xx. */
    public static function rejected(int $responseStatus, ?string $responseBody): self
    {
        return new self(false, $responseStatus, $responseBody, "HTTP {$responseStatus}");
    }

    /** No response at all — timeout, DNS failure, connection refused, ... */
    public static function unreachable(string $errorMessage): self
    {
        return new self(false, null, null, $errorMessage);
    }
}
