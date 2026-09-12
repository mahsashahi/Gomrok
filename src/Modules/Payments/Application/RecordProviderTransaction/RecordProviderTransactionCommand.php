<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\RecordProviderTransaction;

final readonly class RecordProviderTransactionCommand
{
    /**
     * @param array<array-key, mixed>|null $requestPayload
     * @param array<array-key, mixed>|null $responsePayload
     */
    public function __construct(
        public int $clientId,
        public int $paymentId,
        public int $providerAccountId,
        public string $kind,
        public string $providerStatusRaw,
        public string $newStatus,
        public ?array $requestPayload = null,
        public ?array $responsePayload = null,
        public ?string $paymentMethod = null,
        public ?string $attemptOutcome = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?int $actorId = null,
    ) {
    }
}
